# Log Audit Tool (Raspberry Pi)

Prüft die Laravel-Log-Dateien auf erwartete Patterns (MQTT-Events, Daily-Reset, Errors). Liefert Kennzahlen und meldet Auffälligkeiten. Optional: alte Logs nach `retention_days` aus `config.json` löschen.

## Wo das Tool auf dem Pi liegt

`start-prod-raspi.sh` deployt das Verzeichnis automatisch nach:

```
/var/www/lokato/tools/log_audit/
├── log_audit.py
├── config.json
└── README.md
```

Geprüft werden die vier Laravel-Logs unter `/var/www/lokato/backend/storage/logs/`:
- `scan.log` — MQTT-/Scan-Events (`mqtt`-, `scan`-, `db`-Komponenten via `AppLogger`)
- `sse.log` — SSE-Verbindungs-Lifecycle
- `cron.log` — täglicher Reset (`children:daily-active-reset`)
- `laravel.log` — generischer Stack

## Manuelle Läufe

Aus `/var/www/lokato/` (wichtig — `config.json` enthält relative Pfade):

```bash
cd /var/www/lokato

# Daily-Check (letzte 24 h)
python3 tools/log_audit/log_audit.py check --period daily --config tools/log_audit/config.json

# Weekly-Check (letzte 7 Tage)
python3 tools/log_audit/log_audit.py check --period weekly --config tools/log_audit/config.json

# Cleanup (löscht Logs älter als retention_days)
python3 tools/log_audit/log_audit.py cleanup --config tools/log_audit/config.json
```

## Exit-Codes
- `0` — alles ok, keine Errors im Zeitraum
- `1` — Errors oder `daily_reset_failed` gefunden → für Alarmierung geeignet
- `2` — Log-Dateien nicht gefunden (Setup unvollständig)

## Cron-Jobs einrichten

Die Log-Audit-Cron-Einträge stehen im **Root-`README.md` → Abschnitt „Cron-Jobs auf dem Pi"** zusammen mit dem Laravel-Scheduler-Eintrag. Dort ist auch dokumentiert, warum `start-prod-raspi.sh` die Crons **nicht** automatisch anlegt und welche Verifikations-Befehle es gibt.

Kurzform der Audit-Einträge (in `sudo crontab -e -u www-data`):

```cron
10 6 * * * cd /var/www/lokato && /usr/bin/python3 tools/log_audit/log_audit.py check --period daily  --config tools/log_audit/config.json >> /var/log/lokato/log-audit.log 2>&1
20 6 * * 1 cd /var/www/lokato && /usr/bin/python3 tools/log_audit/log_audit.py check --period weekly --config tools/log_audit/config.json >> /var/log/lokato/log-audit.log 2>&1
30 3 * * 0 cd /var/www/lokato && /usr/bin/python3 tools/log_audit/log_audit.py cleanup        --config tools/log_audit/config.json >> /var/log/lokato/log-audit.log 2>&1
```

Cron-Output landet in `/var/log/lokato/log-audit.log`. `start-prod-raspi.sh` legt `/var/log/lokato/` mit `www-data:www-data` an.

## systemd-Check (Juni-2026-Erweiterung)

Zusätzlich zu den Log-Patterns prüft das Tool den Status der unter `systemd_units` in `config.json` aufgelisteten Services:

```json
"systemd_units": ["lokato-mqtt", "nginx", "mariadb", "mosquitto", "php8.4-fpm"],
"restart_counter_warn_threshold": 10
```

Anomalien (in „Auffaelligkeiten"-Sektion + Exit-Code 1):
- **Unit nicht active** — z. B. `lokato-mqtt ist failed (erwartet: active)`
- **NRestarts > Threshold** — z. B. `lokato-mqtt hat 138 Restarts (Schwelle: 10) -- moeglicher Crash-Loop`

Auf Maschinen ohne `systemctl` (z. B. Windows-Dev) wird der Check still übersprungen.

## System-Logs (Juni-2026-Erweiterung)

Zusätzlich zu den Laravel-Logs liest das Tool absolute Pfade aus `system_log_files`:

```json
"system_log_files": [
  "/var/log/nginx/error.log",
  "/var/log/lokato/php-fpm.log",
  "/var/log/lokato/scheduler.log"
]
```

Pattern-Kategorien dafür: `nginx_errors` (5xx, upstream-Fehler) und `scheduler_errors` (PHP Fatal, Uncaught Exceptions).

**Permissions:** nginx-Logs sind in der Regel `640 root:adm`. Damit `www-data` (der cron-User) sie lesen kann, entweder:
- `sudo usermod -aG adm www-data` (einmalig), oder
- Cron als root laufen lassen statt als `www-data`

## Erwartete Patterns

Das Tool sucht (case-insensitive) nach folgenden Strings; jede Trefferzahl wird im Report ausgewiesen:

| Kategorie | Patterns | Wo emittiert |
|---|---|---|
| `scan_success` | `scan_processed`, `mqtt_message_processed`, `movement_id` | `MqttSubscribeCommand`, `DeviceEventController` (über `ScanIngestService`) |
| `mqtt_connected` | `mqtt_connection_initialized` | beim Subscriber-Start |
| `mqtt_subscribed` | `mqtt_subscribed` | nach `subscribe()` |
| `mqtt_received` | `mqtt_message_received` | bei jedem MQTT-Frame |
| `mqtt_ignored` | `mqtt_message_ignored` | leerer/oversized/unbekannt-device Payload |
| `mqtt_validation` | `mqtt_payload_validation_failed`, `mqtt_payload_json_decode_failed`, `mqtt_event_time_invalid` | bei Payload-Fehlern |
| `mqtt_latency` | `mqtt_latency_warning` | Scanner→Broker-Latenz > `MQTT_LATENCY_WARN_MS` |
| `daily_reset_success` | `daily_reset_finished` | `DailyActiveResetCommand` |
| `daily_reset_failed` | `daily_reset_failed` | dito, im Fehlerfall |
| `db_errors` | `SQLSTATE`, `QueryException`, `database`, `db.*failed` | Laravel-Framework |
| `errors` | `error`, `exception`, `failed`, `SQLSTATE` | überall |

## Auffälligkeiten

Das Tool meldet automatisch:
- `Kein daily_reset_finished im Tagesfenster erkannt.` (nur bei `--period daily`)
- `Keine MQTT-Verbindungs-Events gefunden.` — Subscriber war nicht aktiv

## Konfiguration anpassen

`config.json`:
```json
{
  "log_files": [
    "backend/storage/logs/scan.log",
    "backend/storage/logs/cron.log",
    "backend/storage/logs/laravel.log",
    "backend/storage/logs/sse.log"
  ],
  "retention_days": 14,
  "latency_warn_ms": 3000,
  "patterns": { /* … */ }
}
```

- **Pfade**: relativ — werden gegen `cwd`, das config-Verzeichnis und dessen `..` aufgelöst. Wenn du das Tool aus einem anderen Verzeichnis startest, entweder absolute Pfade eintragen oder `cd /var/www/lokato` voranstellen.
- **`retention_days`**: betrifft nur den `cleanup`-Command.
- **`patterns`**: Regex (case-insensitive). Eigene Patterns ergänzen, falls neue Events dazukommen.

## Dev-Modus (Windows mit Docker Compose)

In Dev liegen die Logs unter `backend/storage/logs/` (vom Container per Bind-Mount sichtbar auf dem Host). Aus dem Repo-Root:

```powershell
cd C:\Users\<dein-name>\…\lokato-platform
python tools\log_audit\log_audit.py check --period daily --config tools\log_audit\config.json
```

Python 3 muss installiert sein (`winget install Python.Python.3.12`).
