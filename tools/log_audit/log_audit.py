#!/usr/bin/env python3
"""Log audit tool for production checks (Raspberry Pi/Linux)."""
from __future__ import annotations

import argparse
import datetime as dt
import json
import re
import shutil
import subprocess
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
    "nginx_errors": [r"upstream", r" 5\d{2} ", r"connect\(\) failed", r"no live upstreams", r"emerg", r"alert"],
    "scheduler_errors": [r"PHP Fatal", r"PHP Parse", r"Uncaught", r"Exception"],
    "errors": [r"\berror\b", r"\bexception\b", r"\bfailed\b", r"SQLSTATE"],
}

# Per-category rows in the report, in this order.
REPORT_KEYS = [
    "scan_success",
    "mqtt_connected",
    "mqtt_subscribed",
    "mqtt_received",
    "mqtt_ignored",
    "mqtt_latency",
    "mqtt_validation",
    "db_errors",
    "daily_reset_success",
    "daily_reset_failed",
    "nginx_errors",
    "scheduler_errors",
    "errors",
]


def load_config(path: Path) -> Dict:
    if not path.exists():
        print(f"ERROR: Config file not found: {path}")
        raise FileNotFoundError(path)

    with path.open("r", encoding="utf-8") as f:
        cfg = json.load(f)
    cfg.setdefault("log_files", [])
    cfg.setdefault("system_log_files", [])
    cfg.setdefault("systemd_units", [])
    cfg.setdefault("restart_counter_warn_threshold", 10)
    cfg.setdefault("retention_days", 14)
    cfg.setdefault("patterns", DEFAULT_PATTERNS)
    cfg.setdefault("latency_warn_ms", 3000)
    return cfg


def resolve_log_paths(cfg: Dict, config_path: Path) -> Tuple[List[Path], List[Path]]:
    """Resolve `log_files` + `system_log_files` against cwd / config dir / repo root."""
    existing, missing = [], []
    base = config_path.parent.resolve()
    repo_root = base.parent.parent.resolve() if len(base.parents) >= 2 else base
    cwd = Path.cwd().resolve()

    all_entries = list(cfg["log_files"]) + list(cfg["system_log_files"])
    for entry in all_entries:
        raw = Path(entry)
        candidates = [raw] if raw.is_absolute() else [cwd / raw, base / raw, repo_root / raw]
        found = next((p.resolve() for p in candidates if p.exists()), None)
        if found:
            existing.append(found)
        else:
            missing.append((repo_root / raw).resolve() if not raw.is_absolute() else raw)
    return existing, missing


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
        try:
            with file.open("r", encoding="utf-8", errors="ignore") as f:
                for line in f:
                    if in_period(line, start):
                        yield file, line.strip()
        except PermissionError as exc:
            print(f"WARN: keine Leseberechtigung fuer {file} ({exc})")
        except OSError as exc:
            print(f"WARN: {file} nicht lesbar ({exc})")


# ---------------------------------------------------------------------------
# systemd status check (Linux-only; silently skipped where systemctl is missing)
# ---------------------------------------------------------------------------

def check_systemd_units(cfg: Dict) -> List[str]:
    """Return a list of anomaly strings about configured systemd units.

    Anomalies emitted:
      - unit is not active
      - unit has restarted more than `restart_counter_warn_threshold` times
        (good signal for crash loops; today we hit 138 restarts on lokato-mqtt
        and the audit must surface that automatically)
    """
    anomalies: List[str] = []
    units = cfg.get("systemd_units") or []
    if not units:
        return anomalies

    systemctl = shutil.which("systemctl")
    if not systemctl:
        # Dev-Maschine ohne systemctl (z.B. Windows) — Check still skippen.
        return anomalies

    threshold = int(cfg.get("restart_counter_warn_threshold", 10))

    for unit in units:
        try:
            active = subprocess.run(
                [systemctl, "is-active", unit],
                capture_output=True, text=True, timeout=5,
            )
            state = active.stdout.strip()
            if state != "active":
                anomalies.append(f"systemd-Unit '{unit}' ist {state} (erwartet: active).")

            nrest = subprocess.run(
                [systemctl, "show", "-p", "NRestarts", "--value", unit],
                capture_output=True, text=True, timeout=5,
            )
            try:
                restarts = int(nrest.stdout.strip() or "0")
            except ValueError:
                restarts = 0
            if restarts > threshold:
                anomalies.append(
                    f"systemd-Unit '{unit}' hat {restarts} Restarts (Schwelle: {threshold}) -- moeglicher Crash-Loop."
                )
        except subprocess.TimeoutExpired:
            anomalies.append(f"systemctl-Abfrage fuer '{unit}' timed out.")
        except OSError as exc:
            anomalies.append(f"systemctl-Aufruf fuer '{unit}' fehlgeschlagen: {exc}")

    return anomalies


def run_check(cfg: Dict, period: str, config_path: Path) -> int:
    days = 1 if period == "daily" else 7
    start = dt.date.today() - dt.timedelta(days=days)
    files, missing = resolve_log_paths(cfg, config_path)

    if not files:
        print("ERROR: Keine konfigurierte Logdatei gefunden.")
        print("Hinweis: Die config.json ist vorhanden, aber die unten gelisteten Log-Dateien fehlen aktuell:")
        for m in missing:
            print(f"- {m}")
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
        if dm:
            durations.append(int(dm.group(1)))

    # systemd checks (Linux-only)
    anomalies.extend(check_systemd_units(cfg))

    print(f"\nLog Audit ({period})\n" + "=" * 34)
    print(f"Zeitraum: seit {start.isoformat()}")
    print(f"Scanned lines: {stats['lines']}")
    for key in REPORT_KEYS:
        print(f"{key:20}: {stats.get(key, 0)}")
    print(f"Avg duration ms     : {round(sum(durations)/len(durations),2) if durations else 'n/a'}")

    if missing:
        print("\nWARN: Fehlende Logdateien (werden uebersprungen):")
        for m in missing:
            print(f"- {m}")

    if period == "daily" and stats.get("daily_reset_success", 0) == 0:
        anomalies.append("Kein daily_reset_finished im Tagesfenster erkannt.")
    if stats.get("mqtt_connected", 0) == 0:
        anomalies.append("Keine MQTT-Verbindungs-Events gefunden.")

    if anomalies:
        print("\nAuffaelligkeiten:")
        for a in anomalies:
            print(f"- {a}")

    # Exit-Code-Logik:
    #   2 = harter Setup-Fehler (Log-Files fehlen)
    #   1 = Errors / Crash-Loop / daily_reset_failed / Anomalien
    #   0 = clean
    if stats.get("errors", 0) > 0 or stats.get("daily_reset_failed", 0) > 0 or anomalies:
        return 1
    return 0


def run_cleanup(cfg: Dict, config_path: Path) -> int:
    files, missing = resolve_log_paths(cfg, config_path)
    now = dt.datetime.now()
    deleted = 0
    bytes_freed = 0
    for path in files:
        try:
            age_days = (now - dt.datetime.fromtimestamp(path.stat().st_mtime)).days
            if age_days > int(cfg["retention_days"]):
                size = path.stat().st_size
                path.unlink(missing_ok=True)
                deleted += 1
                bytes_freed += size
                print(f"deleted: {path} ({size} bytes)")
        except PermissionError as exc:
            print(f"WARN: cleanup uebersprungen fuer {path} ({exc})")
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
    try:
        cfg = load_config(config_path)
    except FileNotFoundError:
        return 2
    except json.JSONDecodeError as exc:
        print(f"ERROR: Invalid JSON in config: {config_path} ({exc})")
        return 2

    return run_check(cfg, args.period, config_path) if args.command == "check" else run_cleanup(cfg, config_path)


if __name__ == "__main__":
    raise SystemExit(main())
