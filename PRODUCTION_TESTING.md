# Production Testing (Raspberry Pi, LAN)

## Start
- MySQL/Mosquitto in Docker starten: `docker compose -f docker/docker-compose.yml up -d`
- Backend lokal starten, dann `php artisan schedule:work` für den 01:00 Reset.

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

## Backup
Vor Migrationen ein DB-Dump: `mysqldump -h <host> -u <user> -p <db> > backup.sql`
