# Production Testing (Raspberry Pi, LAN)

## Start
- MySQL/Mosquitto in Docker starten: `docker compose -f docker/docker-compose.yml up -d`
- Backend lokal starten.
- Laravel Scheduler laufen lassen, damit der Daily Reset um 01:00 ausgeführt wird: `php artisan schedule:work`.
- Alternativ per System-Cron minütlich: `* * * * * cd /workspace/lokato-platform/backend && php artisan schedule:run >> /dev/null 2>&1`

## Pflicht-Befehle nach .env Änderungen
- `php artisan config:clear`
- `php artisan cache:clear`

## MQTT Topic Hinweis
- MQTT Topics sind exakt. `/api/v1/scan` und `api/v1/scan` sind **unterschiedliche** Topics.
- `MQTT_TOPIC_SCAN` muss exakt zum Publisher passen (inkl. führendem Slash).

## Log Profile
Normal:
- LOG_ENABLED=true
- LOG_LEVEL=info
- SCAN_DIAGNOSTIC_LOGS=false
- MQTT_DIAGNOSTIC_LOGS=false

Fehlersuche:
- LOG_ENABLED=true
- LOG_LEVEL=debug
- SCAN_DIAGNOSTIC_LOGS=true
- MQTT_DIAGNOSTIC_LOGS=true
- DB_DIAGNOSTIC_LOGS=true

Leise:
- LOG_LEVEL=warn
- MQTT_DIAGNOSTIC_LOGS=false
- SCAN_DIAGNOSTIC_LOGS=false
- DB_DIAGNOSTIC_LOGS=false

## MQTT-Latenz
Warnungen erscheinen bei `mqtt_latency_warning`, wenn `mqtt_delivery_latency_ms > MQTT_LATENCY_WARN_MS`.

## Health/Readiness
- `/api/health`
- `/api/readiness`
- `/api/diagnostics` (wenn `DIAGNOSTICS_ENABLED=true`)

## Docker Logs
- MySQL: `docker logs lokato-mysql`
- Mosquitto: `docker logs lokato-mosquitto`

## Subscriber Start (Produktionstest)
- `php artisan mqtt:subscribe --debug`
- Einmaltest: `php artisan mqtt:subscribe --once --debug`
- Log prüfen: `tail -f backend/storage/logs/scan.log`

## Daily Reset Test
- Manuell ausführen: `php artisan children:daily-active-reset`
- Erwartete Events: `daily_reset_started`, `daily_reset_finished` (oder `daily_reset_failed`).
- Log prüfen: `tail -f backend/storage/logs/cron.log`

## Typische Fehlerbilder
- Keine Einträge in `scan.log`: falscher Channel oder falsche Dateirechte auf `backend/storage/logs`.
- Subscriber disconnectet ständig: gleiche `MQTT_CLIENT_ID` in mehreren Prozessen.
- Logs landen nur in `laravel.log`: Config-Cache nicht geleert.
- Audit findet nichts: falsches Arbeitsverzeichnis oder nicht vorhandene Logdateien.

## Backup
Vor Migrationen ein DB-Dump: `mysqldump -h <host> -u <user> -p <db> > backup.sql`

## Log-Audit: täglich/wöchentlich per Cron einrichten

### 1) Cron-Editor öffnen
```bash
crontab -e
```
Beim ersten Mal einen Editor wählen (z. B. `nano`).

### 2) Diese Jobs eintragen
> Passe den Pfad `/workspace/lokato-platform` und ggf. den Python-Pfad `/usr/bin/python3` an dein System an.

```cron
# Daily Check: jeden Tag um 06:10
10 6 * * * cd /workspace/lokato-platform/tools/log_audit && /usr/bin/python3 log_audit.py check --period daily --config config.json >> /var/log/lokato-log-audit.log 2>&1

# Weekly Check: jeden Montag um 06:20
20 6 * * 1 cd /workspace/lokato-platform/tools/log_audit && /usr/bin/python3 log_audit.py check --period weekly --config config.json >> /var/log/lokato-log-audit.log 2>&1

# Optional Cleanup: jeden Sonntag um 03:30
30 3 * * 0 cd /workspace/lokato-platform/tools/log_audit && /usr/bin/python3 log_audit.py cleanup --config config.json >> /var/log/lokato-log-audit.log 2>&1
```

### 3) Speichern und Cron neu laden
- In `nano`: `CTRL+O`, Enter, dann `CTRL+X`.
- Cron lädt die Änderungen automatisch.

### 4) Prüfen, ob die Jobs gespeichert wurden
```bash
crontab -l
```

### 5) Manuell testen (empfohlen)
```bash
cd /workspace/lokato-platform/tools/log_audit
python3 log_audit.py check --period daily --config config.json
python3 log_audit.py check --period weekly --config config.json
python3 log_audit.py cleanup --config config.json
```

### 6) Ergebnisse ansehen
- Cron-Output: `/var/log/lokato-log-audit.log`
- App-Logs: `backend/storage/logs/laravel.log`, `backend/storage/logs/scan.log`, `backend/storage/logs/sse.log`

## Manuelle Produktions-Test-Checkliste
- Laravel startet und Health ist erreichbar (`/api/health`).
- DB ist erreichbar (z. B. `php artisan migrate:status`).
- MQTT Subscriber verbindet (`mqtt_connection_initialized`, `mqtt_subscribed`).
- Test-MQTT Nachricht wird empfangen (`mqtt_message_received`).
- Scan wird verarbeitet (`mqtt_message_processed`) oder sauber ignoriert (`mqtt_message_ignored`).
- `scan.log` und `cron.log` enthalten die erwarteten Events.
- `python3 tools/log_audit/log_audit.py check --period daily --config tools/log_audit/config.json` liefert erwarteten Status.

### Kurz erklärt (Cron-Felder)
`10 6 * * *` bedeutet:
- Minute `10`
- Stunde `6`
- jeden Tag im Monat
- jeden Monat
- jeder Wochentag

`20 6 * * 1` bedeutet Montag (`1`) um 06:20.
`30 3 * * 0` bedeutet Sonntag (`0`) um 03:30.
