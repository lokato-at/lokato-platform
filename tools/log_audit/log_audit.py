#!/usr/bin/env python3
"""Log audit tool for production checks (Raspberry Pi/Linux)."""
from __future__ import annotations
import argparse, datetime as dt, json, re
from pathlib import Path
from typing import Dict, Iterable, List, Tuple

DATE_RE = re.compile(r"(\d{4}-\d{2}-\d{2})")
DURATION_RE = re.compile(r"(?:duration_ms|processing_duration_ms|db_duration_ms|total_app_latency_ms|mqtt_delivery_latency_ms)\D+(\d+)")

DEFAULT_PATTERNS = {
    "scan_success": [r"scan_processed", r"mqtt_message_processed", r"movement_id"],
    "mqtt_connected": [r"mqtt_connection_initialized"],
    "mqtt_subscribed": [r"mqtt_subscribed"],
    "mqtt_received": [r"mqtt_message_received"],
    "mqtt_ignored": [r"mqtt_message_ignored"],
    "mqtt_latency": [r"mqtt_latency_warning"],
    "mqtt_validation": [r"mqtt_payload_validation_failed", r"mqtt_payload_json_decode_failed", r"mqtt_event_time_invalid"],
    "db_errors": [r"SQLSTATE", r"QueryException", r"database", r"db.*failed"],
    "daily_reset_success": [r"daily_reset_finished"],
    "daily_reset_failed": [r"daily_reset_failed"],
    "errors": [r"\berror\b", r"\bexception\b", r"\bfailed\b", r"SQLSTATE"],
}

def load_config(path: Path) -> Dict:
    with path.open("r", encoding="utf-8") as f:
        cfg = json.load(f)
    cfg.setdefault("log_files", [])
    cfg.setdefault("retention_days", 14)
    cfg.setdefault("patterns", DEFAULT_PATTERNS)
    cfg.setdefault("latency_warn_ms", 3000)
    return cfg

def resolve_log_paths(cfg: Dict, config_path: Path) -> Tuple[List[Path], List[Path]]:
    existing, missing = [], []
    base = config_path.parent.resolve()
    cwd = Path.cwd().resolve()
    for entry in cfg["log_files"]:
        raw = Path(entry)
        candidates = [raw] if raw.is_absolute() else [cwd / raw, base / raw]
        found = next((p.resolve() for p in candidates if p.exists()), None)
        if found:
            existing.append(found)
        else:
            missing.append((cwd / raw).resolve())
    return existing, missing

def in_period(line: str, start: dt.date) -> bool:
    m = DATE_RE.search(line)
    if not m: return True
    try: return dt.date.fromisoformat(m.group(1)) >= start
    except ValueError: return True

def iter_lines(files: Iterable[Path], start: dt.date):
    for file in files:
        with file.open("r", encoding="utf-8", errors="ignore") as f:
            for line in f:
                if in_period(line, start):
                    yield file, line.strip()

def run_check(cfg: Dict, period: str, config_path: Path) -> int:
    days = 1 if period == "daily" else 7
    start = dt.date.today() - dt.timedelta(days=days)
    files, missing = resolve_log_paths(cfg, config_path)
    if not files:
        print("ERROR: Keine Logdatei gefunden. Bitte config.json prüfen.")
        return 2

    patt = {k: [re.compile(x, re.IGNORECASE) for x in v] for k, v in cfg["patterns"].items()}
    stats = {k: 0 for k in patt.keys()}
    stats["lines"] = 0
    durations: List[int] = []
    anomalies: List[str] = []

    for file, line in iter_lines(files, start):
        stats["lines"] += 1
        for key, regexes in patt.items():
            if any(r.search(line) for r in regexes):
                stats[key] += 1
        dm = DURATION_RE.search(line)
        if dm: durations.append(int(dm.group(1)))

    print(f"\nLog Audit ({period})\n" + "=" * 34)
    print(f"Zeitraum: seit {start.isoformat()}")
    print(f"Scanned lines: {stats['lines']}")
    for key in ["scan_success","mqtt_connected","mqtt_subscribed","mqtt_received","mqtt_ignored","mqtt_latency","mqtt_validation","db_errors","daily_reset_success","daily_reset_failed","errors"]:
        print(f"{key:20}: {stats.get(key, 0)}")
    print(f"Avg duration ms     : {round(sum(durations)/len(durations),2) if durations else 'n/a'}")

    if missing:
        print("\nWARN: Fehlende Logdateien:")
        for m in missing:
            print(f"- {m}")

    if period == "daily" and stats.get("daily_reset_success", 0) == 0:
        anomalies.append("Kein daily_reset_finished im Tagesfenster erkannt.")
    if stats.get("mqtt_connected", 0) == 0:
        anomalies.append("Keine MQTT-Verbindungs-Events gefunden.")

    if anomalies:
        print("\nAuffälligkeiten:")
        for a in anomalies: print(f"- {a}")

    if missing:
        return 2
    return 1 if stats.get("errors", 0) > 0 or stats.get("daily_reset_failed", 0) > 0 else 0

def run_cleanup(cfg: Dict, config_path: Path) -> int:
    files, missing = resolve_log_paths(cfg, config_path)
    now = dt.datetime.now(); deleted=0; bytes_freed=0
    for path in files:
        age_days = (now - dt.datetime.fromtimestamp(path.stat().st_mtime)).days
        if age_days > int(cfg["retention_days"]):
            size = path.stat().st_size; path.unlink(missing_ok=True); deleted += 1; bytes_freed += size
            print(f"deleted: {path} ({size} bytes)")
    if missing:
        print("WARN: Einige konfigurierte Logdateien fehlen und konnten nicht bereinigt werden.")
    print(f"\nCleanup done. Deleted files: {deleted}, freed: {bytes_freed} bytes")
    return 2 if missing else 0

def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("command", choices=["check", "cleanup"])
    ap.add_argument("--period", choices=["daily", "weekly"], default="daily")
    ap.add_argument("--config", default="config.json")
    args = ap.parse_args()
    config_path = Path(args.config).resolve()
    cfg = load_config(config_path)
    return run_check(cfg, args.period, config_path) if args.command == "check" else run_cleanup(cfg, config_path)

if __name__ == "__main__":
    raise SystemExit(main())
