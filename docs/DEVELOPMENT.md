# Development — Windows 11 + Docker Compose

Vollständige Anleitung für die lokale Entwicklung. Quick-Start steht in der Haupt-`README.md`.

---

## Voraussetzungen

| Tool | Install-Befehl | Wofür |
|---|---|---|
| Docker Desktop (mit WSL2) | `winget install Docker.DockerDesktop` | Backend-Stack im Container |
| Node.js LTS (≥ 20.19) | `winget install OpenJS.NodeJS.LTS` | Vue-Dev mit HMR |
| Git | `winget install Git.Git` | Repo klonen |

Docker Desktop nach Install **einmal starten** — Whale-Symbol muss „Running" zeigen.

## Erststart von Null

```powershell
git clone https://github.com/lokato-at/lokato-platform.git
cd lokato-platform

# 1) backend\.env aus Template (existiert nach git clone NICHT)
Copy-Item backend\.env.example backend\.env

# 2) ⚠️ ZWEI Zeilen in backend\.env auf Compose-Werte umstellen
#    DB_HOST=db        (statt 127.0.0.1)
#    MQTT_HOST=mqtt    (statt 127.0.0.1)
notepad backend\.env

# 3) Backend-Stack hochfahren (~2 Min beim ersten Lauf, php-fpm-Image-Build)
cd docker
docker compose up -d
cd ..

# 4) Composer + APP_KEY + Migrate + Seed
docker compose -f docker/docker-compose.yml exec php-fpm composer install
docker compose -f docker/docker-compose.yml exec php-fpm php artisan key:generate
docker compose -f docker/docker-compose.yml exec php-fpm php artisan migrate --force
docker compose -f docker/docker-compose.yml exec php-fpm php artisan db:seed --force

# 5) Zweites Terminal: Vue mit HMR
cd frontend
npm install
npm run dev
```

App-URL: **http://localhost** (nicht `:5173` — `localhost` geht über den nginx, der wiederum zu Vite-Dev proxyt).

## Routing-Topologie (Compose)

```
Browser → http://localhost (Port 80, nginx-Container)
            ├── /          → host.docker.internal:5173 (Vite-Dev mit HMR)
            └── /api/*     → FastCGI zu php-fpm:9000
                              ├── /api/v1/*  → REST-Controller
                              ├── /api/stream → SSE-Stream
                              └── /api/health → Diagnostics
```

## `backend/.env` — die zwei Werte je Modus

`.env.example` ist auf Host-Mode-Defaults (`127.0.0.1`). Für Compose musst du **zwei** Zeilen umstellen:

| Variable | Host-Mode (Default) | Compose-Mode |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | `db` |
| `MQTT_HOST` | `127.0.0.1` | `mqtt` |

Alle anderen Werte sind in beiden Modi identisch korrekt — insbesondere `MQTT_AUTH_USERNAME=null` (nicht leer!) und alle Driver auf `database`.

## Nach jeder `.env`-Änderung

```powershell
docker compose exec php-fpm php artisan config:clear
docker compose restart php-fpm mqtt-subscriber
```

## Dev-Befehls-Cheat-Sheet

| Was | Befehl |
|---|---|
| Stack starten | `cd docker; docker compose up -d` |
| Stack stoppen | `cd docker; docker compose down` |
| Stack reset (DB-Volume weg!) | `cd docker; docker compose down -v` |
| Container-Status | `docker compose ps` |
| Live-Log eines Services | `docker compose logs -f mqtt-subscriber` |
| Composer install | `docker compose exec php-fpm composer install` |
| Migrations | `docker compose exec php-fpm php artisan migrate --force` |
| Seed | `docker compose exec php-fpm php artisan db:seed --force` |
| DB reset + frisch seeden | `docker compose exec php-fpm php artisan migrate:fresh --seed --force` |
| Cache leeren (nach `.env`) | `docker compose exec php-fpm php artisan config:clear` |
| php-fpm + MQTT neu starten | `docker compose restart php-fpm mqtt-subscriber` |
| Laravel REPL | `docker compose exec php-fpm php artisan tinker` |
| Vue-Dev | `cd frontend; npm run dev` |
| Type-check | `cd frontend; npm run type-check` |
| Lint | `cd frontend; npm run lint` |
| Tests | `cd frontend; npm run test:unit` |

## URLs in Dev

| Dienst | URL |
|---|---|
| App (Frontend + Backend) | http://localhost |
| API-Health | http://localhost/api/health |
| API-Readiness | http://localhost/api/readiness |
| phpMyAdmin | http://localhost:8090 |
| MySQL (extern) | localhost:3306 |
| MQTT-Broker | localhost:1883 |

## MQTT-Scan testen

PowerShell-Quoting für JSON ist brüchig — JSON in eine **single-quoted Variable** packen:

```powershell
$payload = '{"device_key":"RaspberryChild02","tracker_uid":"0x80691500004023FDD55DFC23","event_time":"2026-06-04T10:00:00+02:00"}'
docker exec lokato-mosquitto mosquitto_pub -t "/api/v1/scan" -m $payload
```

Verfügbare Seed-Kombinationen:

| `device_key` | Raum | Beispiel-`tracker_uid` |
|---|---|---|
| `RaspberryChild01` | Untergeschoss (3) | `0x80691500005023FDD541FC7F` (GELB-HERZ) |
| `RaspberryChild02` | Obergeschoss (2) | `0x80691500004023FDD55DFC23` (SCHWARZ-BLUME) |
| `RaspberryChild03` | Garten (1) | `0x80691500004023FDD525FCA2` (BLAU-BLUME) |

Erwartet: `docker compose logs -f mqtt-subscriber` zeigt `mqtt_message_processed`, Tablets/Dashboard updaten innerhalb 500 ms.
