# Lokato Platform

Hort-Anwesenheitssystem mit RFID-Scannern. ESP32 publiziert Scans per MQTT, Laravel verarbeitet, Vue zeigt live an.

Übergeordnetes Repo: [Lokato-main](https://github.com/lokato-at/lokato-main.git).

---

## Architektur

```
ESP32/RFID --MQTT--> Mosquitto --subscribe--> Laravel (php artisan mqtt:subscribe)
                                                       |
                                              ScanIngestService -> MariaDB
                                                       |
                                              SseChangeSignal::bump()
                                                       |
              Vue (EventSource /api/stream) <--SSE-- SseStreamController
```

Frontend und Backend laufen **same-origin** via nginx. Vue ist ein statisches Build, Laravel hängt unter `/api/*`.

## Stack

| Schicht | Technologie |
|---|---|
| Frontend | Vue 3.5 + TypeScript + Pinia + Vite 7 |
| Backend | Laravel 12 (PHP ≥ 8.2) |
| DB | MySQL 8 (Dev, Docker) / MariaDB (Prod, Pi nativ) |
| MQTT | Mosquitto 2 |
| Realtime | Server-Sent Events (single endpoint mit Cache-Wakeup) |
| Reverse Proxy | nginx (Dev im Container, Prod nativ) |
| PHP-Laufzeit | php-fpm |

---

## Dokumentations-Übersicht

Detail-Anleitungen pro Use-Case:

| Datei | Inhalt |
|---|---|
| **[`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md)** | Windows 11 + Docker Compose, Erststart, Befehls-Cheat-Sheet, MQTT-Test |
| **[`docs/PRODUCTION.md`](docs/PRODUCTION.md)** | Raspberry Pi nativ: `start-prod-raspi.sh`, Smoketest, Pi-IP wechseln, Test-Checkliste |
| **[`docs/CRON.md`](docs/CRON.md)** | Laravel-Scheduler + Log-Audit + Mail-Alarmierung |
| **[`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md)** | 4 Hauptszenarien (WLAN, MQTT-down, MySQL-refused, IP-Wechsel) + weitere Stolpersteine |
| **[`backend/README.md`](backend/README.md)** | Backend-Code-Layout, Routen-Tabelle, SSE-Modi, Logging, ENV-Variablen |
| **[`frontend/README.md`](frontend/README.md)** | Frontend-Code-Layout, Stores, SSE-Pattern, npm-Scripts |
| **[`frontend/frontend_explanation.md`](frontend/frontend_explanation.md)** | Frontend-Architektur (ausführlich) |
| **[`tools/log_audit/README.md`](tools/log_audit/README.md)** | Log-Audit-Tool im Detail |
| **[`CLAUDE.md`](CLAUDE.md)** | Architekturentscheidungen + „nicht anfassen"-Bereiche + `.env`-Quirks |
| **[`BETREUUNG.md`](BETREUUNG.md)** | Briefing für die Betreuung — Was wurde gemacht und warum |
| **[`VERBESSERUNGSVORSCHLAEGE.md`](VERBESSERUNGSVORSCHLAEGE.md)** | Strukturierte Verbesserungs-Analyse |
| **[`PRODUCTION_TESTING.md`](PRODUCTION_TESTING.md)** | Pi-Test-Checkliste (vorbestehend, weiter gültig) |

---

## Quick-Start

### Windows-Dev (Compose)

```powershell
git clone https://github.com/lokato-at/lokato-platform.git
cd lokato-platform
Copy-Item backend\.env.example backend\.env
# 2 Zeilen in backend\.env: DB_HOST=db, MQTT_HOST=mqtt
cd docker
docker compose up -d
docker compose exec php-fpm composer install
docker compose exec php-fpm php artisan key:generate
docker compose exec php-fpm php artisan migrate --force
docker compose exec php-fpm php artisan db:seed --force
cd ..\frontend
npm install
npm run dev
```

App-URL: **http://localhost** (nicht `:5173`). Vollständige Anleitung mit Troubleshooting in `docs/DEVELOPMENT.md`.

### Raspberry Pi (Produktion)

```bash
git clone https://github.com/lokato-at/lokato-platform.git /home/pi/lokato-platform
cd /home/pi/lokato-platform
export PI_IP=192.168.1.100
export DB_PASSWORD="ein-starkes-passwort"
chmod +x start-prod-raspi.sh stop-prod-raspi.sh
./start-prod-raspi.sh
```

Idempotent, ~5-10 Min beim ersten Lauf. Vollständige Anleitung in `docs/PRODUCTION.md`.

---

## Verzeichnis-Layout

```
backend/                      Laravel-Source
frontend/                     Vue-Source
docker/
  docker-compose.yml          Dev-Stack (Win11 + Docker Desktop)
  nginx/{dev,prod}.conf       nginx-Configs für Container und Pi
  php-fpm/                    Dockerfile + Pool-Configs
  systemd/lokato-mqtt.service systemd-Unit für MQTT-Subscriber
  mosquitto/                  Mosquitto-Config
  sql/init/                   Initial-Schema
tools/log_audit/              Python-Audit-Tool
docs/                         Detail-Dokumentation
start-prod-raspi.sh           Pi-Komplett-Setup
stop-prod-raspi.sh            Pi-Dienste stoppen
start-dev.ps1                 Win-Dev Komfort-Wrapper (Legacy Host-Mode)
```

---

## Wichtige Pfade nach Pi-Deploy

```
/var/www/lokato/backend/             Laravel-Source
/var/www/lokato/frontend/dist/       Statisches Vue-Build
/var/www/lokato/tools/log_audit/     Audit-Tool
/var/log/lokato/                     scheduler.log, log-audit.log, php-fpm.log
/etc/nginx/sites-available/lokato    Pi-nginx-vhost
/etc/php/X.Y/fpm/pool.d/lokato.conf  Pi-php-fpm-Pool
/etc/systemd/system/lokato-mqtt.service
```

---

## Was NICHT angefasst werden soll

- `backend/app/Services/ScanIngestService.php` — zentrale Scan-Verarbeitungslogik. Änderungen nur außerhalb von Phase 2/3 mit explizitem Auftrag.
- Fachliche Geschäftsregeln in `MovementLog`, `ChildLocation`, `Alert`-Modellen.
- Validierungs-Code in `MqttSubscribeCommand` (das einzig erlaubte Add: `SseChangeSignal::bump()`-Aufruf nach erfolgreichem Ingest).

Siehe `CLAUDE.md` für die vollständige Architekturentscheidungs-Übersicht.
