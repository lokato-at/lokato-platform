#!/usr/bin/env python3
"""Minimal log audit tool for Raspberry Pi Linux.

Usage:
  python3 log_audit.py check --period daily --config config.json
  python3 log_audit.py check --period weekly --config config.json
  python3 log_audit.py cleanup --config config.json
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import re
from pathlib import Path
from typing import Dict, Iterable, List

DATE_RE = re.compile(r"(\d{4}-\d{2}-\d{2})")
DURATION_RE = re.compile(r"(?:duration_ms|processing_duration_ms|db_duration_ms)\D+(\d+)")

DEFAULT_PATTERNS = {
    "scan_events": [
        r"mqtt_message_processed",
        r"scan_processed",
        r"MQTT scan ingested",
        r"movement_id",
    ],
    "warnings": [r"\bwarning\b", r"mqtt_latency_warning", r"ignored"],
    "errors": [r"\berror\b", r"exception", r"failed", r"SQLSTATE"],
    "daily_reset": [r"daily_reset_finished", r"children:daily-active-reset"],
}


def load_config(path: Path) -> Dict:
    with path.open("r", encoding="utf-8") as f:
        cfg = json.load(f)
    cfg.setdefault("log_files", [])
    cfg.setdefault("retention_days", 14)
    cfg.setdefault("patterns", DEFAULT_PATTERNS)
    cfg.setdefault("latency_warn_ms", 3000)
    return cfg


def in_period(line: str, start: dt.date) -> bool:
    m = DATE_RE.search(line)
    if not m:
        return True
    try:
        return dt.date.fromisoformat(m.group(1)) >= start
    except ValueError:
        return True


def iter_lines(files: Iterable[Path], start: dt.date):
    for file in files:
        if not file.exists():
            continue
        with file.open("r", encoding="utf-8", errors="ignore") as f:
            for line in f:
                if in_period(line, start):
                    yield file, line.strip()


def run_check(cfg: Dict, period: str) -> int:
    days = 1 if period == "daily" else 7
    start = dt.date.today() - dt.timedelta(days=days)
    files = [Path(p) for p in cfg["log_files"]]
    patt = {k: [re.compile(x, re.IGNORECASE) for x in v] for k, v in cfg["patterns"].items()}

    stats = {"lines": 0, "scans": 0, "warnings": 0, "errors": 0, "resets": 0}
    durations: List[int] = []
    anomalies: List[str] = []

    for file, line in iter_lines(files, start):
        stats["lines"] += 1
        if any(r.search(line) for r in patt["scan_events"]):
            stats["scans"] += 1
        if any(r.search(line) for r in patt["warnings"]):
            stats["warnings"] += 1
        if any(r.search(line) for r in patt["errors"]):
            stats["errors"] += 1
            anomalies.append(f"{file.name}: {line[:180]}")
        if any(r.search(line) for r in patt["daily_reset"]):
            stats["resets"] += 1

        dm = DURATION_RE.search(line)
        if dm:
            durations.append(int(dm.group(1)))

        if "mqtt_delivery_latency_ms" in line:
            lm = re.search(r"mqtt_delivery_latency_ms\D+(\d+)", line)
            if lm and int(lm.group(1)) > cfg["latency_warn_ms"]:
                anomalies.append(f"High MQTT latency: {line[:180]}")

    avg_duration = round(sum(durations) / len(durations), 2) if durations else None

    print(f"\nLog Audit ({period})")
    print("=" * 30)
    print(f"Scanned lines: {stats['lines']}")
    print(f"Scan events:   {stats['scans']}")
    print(f"Warnings:      {stats['warnings']}")
    print(f"Errors:        {stats['errors']}")
    print(f"Reset events:  {stats['resets']}")
    print(f"Avg duration:  {avg_duration if avg_duration is not None else 'n/a'} ms")

    print("\nAuffälligkeiten:")
    if anomalies:
        for a in anomalies[:20]:
            print(f"- {a}")
    else:
        print("- keine")

    if period == "daily" and stats["resets"] == 0:
        print("\nWARN: Kein täglicher Reset im betrachteten Zeitraum gefunden.")

    return 0 if stats["errors"] == 0 else 1


def run_cleanup(cfg: Dict) -> int:
    now = dt.datetime.now()
    deleted = 0
    bytes_freed = 0
    for path in [Path(p) for p in cfg["log_files"]]:
        if not path.exists():
            continue
        age_days = (now - dt.datetime.fromtimestamp(path.stat().st_mtime)).days
        if age_days > int(cfg["retention_days"]):
            size = path.stat().st_size
            path.unlink(missing_ok=True)
            deleted += 1
            bytes_freed += size
            print(f"deleted: {path} ({size} bytes)")
    print(f"\nCleanup done. Deleted files: {deleted}, freed: {bytes_freed} bytes")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("command", choices=["check", "cleanup"])
    ap.add_argument("--period", choices=["daily", "weekly"], default="daily")
    ap.add_argument("--config", default="config.json")
    args = ap.parse_args()

    cfg = load_config(Path(args.config))
    if args.command == "check":
        return run_check(cfg, args.period)
    return run_cleanup(cfg)


if __name__ == "__main__":
    raise SystemExit(main())
