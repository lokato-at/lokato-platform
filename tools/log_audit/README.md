# Minimal Log Audit Tool (Raspberry Pi Linux)

## Start
```bash
cd tools/log_audit
python3 log_audit.py check --period daily --config config.json
python3 log_audit.py check --period weekly --config config.json
python3 log_audit.py cleanup --config config.json
```

## Was es macht
- wertet vorhandene Logdateien für `daily` oder `weekly` aus
- zeigt Kennzahlen: Scan-Events, Warnungen, Errors, Reset-Events, mittlere Dauer
- listet Auffälligkeiten (Errors / hohe MQTT-Latenz)
- löscht alte Logs (`retention_days`) zum Platzsparen

## Anpassung
- Dateipfade, Retention und Muster in `config.json` ändern.
- Das Tool ist absichtlich flexibel: wenn ein Log-Format anders ist, nur Patterns anpassen.
- Standardmäßig werden `backend/storage/logs/scan.log` (MQTT-/Scan-Diagnostik) und `backend/storage/logs/laravel.log` (allgemeine App-Logs) ausgewertet.

## Täglicher Lauf (cron)
```bash
crontab -e
```
Beispiel 06:10 täglich:
```cron
10 6 * * * cd /workspace/lokato-platform/tools/log_audit && /usr/bin/python3 log_audit.py check --period daily --config config.json >> /var/log/lokato-log-audit.log 2>&1
```

## Wöchentlicher Lauf (cron)
Beispiel Montag 06:20:
```cron
20 6 * * 1 cd /workspace/lokato-platform/tools/log_audit && /usr/bin/python3 log_audit.py check --period weekly --config config.json >> /var/log/lokato-log-audit.log 2>&1
```

## Cleanup-Lauf (cron)
Beispiel Sonntag 03:30:
```cron
30 3 * * 0 cd /workspace/lokato-platform/tools/log_audit && /usr/bin/python3 log_audit.py cleanup --config config.json >> /var/log/lokato-log-audit.log 2>&1
```
