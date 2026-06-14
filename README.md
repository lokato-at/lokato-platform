# Lokato Platform — Backend, Frontend, Datenbank

Hort-Anwesenheitssystem mit RFID-Scannern. ESP32 publiziert Scans per MQTT, Laravel verarbeitet und pusht Live-Updates per Server-Sent Events ans Vue-Frontend.

Übergeordnetes Repo: [Lokato-main](https://github.com/lokato-at/lokato-main.git).

---

## Architektur

```
ESP32/RFID  --MQTT-->  Mosquitto  --subscribe-->  Laravel (php artisan mqtt:subscribe)
                                                       |
                                                       v
                                              ScanIngestService --> MariaDB
                                                       |
                                                       v
                                              SseChangeSignal::bump()
                                                       |
                                                       v
                              Vue (EventSource /api/stream) <--SSE-- SseStreamController
```

Frontend und Backend laufen **same-origin** via nginx. Vue ist ein statisches Build (`frontend/dist`), Laravel hängt unter `/api/*`.

---

## Stack

| Schicht | Technologie |
|---|---|
| Frontend | Vue 3.5 + TypeScript + Pinia + Vite 7 |
| Backend | Laravel 12 (PHP ≥ 8.2) |
| DB | MySQL 8 (Dev, Docker) / MariaDB (Prod, Pi nativ) |
| MQTT | Mosquitto 2 |
| Realtime | Server-Sent Events (single endpoint mit Cache-Wakeup) |
| Reverse Proxy | nginx (Dev im Container, Prod nativ auf dem Pi) |
| PHP-Laufzeit | php-fpm (Dev: `php:8.3-fpm-alpine` Container; Prod: `php8.x-fpm` nativ) |

---

## Verzeichnisse

```
backend/          Laravel-Source
frontend/         Vue-Source
docker/
  docker-compose.yml          Dev-Stack (Win11 + Docker Desktop)
  nginx/
    dev.conf                  nginx-Config für Compose-Container
    prod.conf                 nginx-vhost für Pi (kopiert nach /etc/nginx/sites-available/lokato)
  php-fpm/
    Dockerfile                php:8.3-fpm-alpine + Extensions für Dev
    php-overrides.ini         PHP-Settings (Dev)
    lokato-pool.conf          php-fpm Pool-Config für Pi (kopiert nach /etc/php/X.Y/fpm/pool.d/)
  systemd/
    lokato-mqtt.service       systemd-Unit für MQTT-Subscriber (kopiert nach /etc/systemd/system/)
  mosquitto/                  Mosquitto-Config + Datenverzeichnisse
  sql/init/                   Initial-Schema, wird beim ersten Compose-Start importiert
start-prod-raspi.sh           Komplettes Pi-Setup (siehe Prod-Sektion)
stop-prod-raspi.sh            Stoppt Lokato-Dienste auf dem Pi
start-dev.ps1                 Komfort-Wrapper für Win-Dev
```

---

## Windows 11 — Entwicklungs-Setup

### Voraussetzungen

| Tool | Install-Befehl | Wofür |
|---|---|---|
| Docker Desktop (mit WSL2) | `winget install Docker.DockerDesktop` | Backend-Stack im Container |
| Node.js LTS (≥ 20.19) | `winget install OpenJS.NodeJS.LTS` | Vue-Dev mit HMR |
| Git | `winget install Git.Git` | Repo klonen |
| (optional) PHP 8.2+ | `winget install PHP.PHP` | Backend-Composer-Befehle auf dem Host |
| (optional) Composer | `winget install Composer.Composer` | dito |

Docker Desktop nach Install **einmal starten**; das Whale-Symbol muss „Running" zeigen.

### Erststart (von 0 auf laufende App)

Komplette Reihenfolge — jeder Schritt einmal ausführen:

```powershell
# 1) Repo klonen
git clone https://github.com/lokato-at/lokato-platform.git
cd lokato-platform

# 2) backend\.env aus Template anlegen (existiert nach git clone NICHT)
Copy-Item backend\.env.example backend\.env

# 3) ⚠️ ZWEI Zeilen in backend\.env auf Compose-Werte umstellen — sonst
#    schlägt jeder API-Request mit "Connection refused" fehl:
#      DB_HOST=db        (statt 127.0.0.1)
#      MQTT_HOST=mqtt    (statt 127.0.0.1)
#    Editor öffnen und ändern:
notepad backend\.env

# 4) Backend-Stack im Docker hochfahren (MySQL, Mosquitto, phpMyAdmin, nginx, php-fpm, MQTT-Subscriber)
#    Beim ersten Lauf ~2 Min für den php-fpm-Image-Build
cd docker
docker compose up -d
cd ..

# 5) Backend-Dependencies installieren (vendor/ existiert sonst nicht)
docker compose -f docker/docker-compose.yml exec php-fpm composer install

# 6) APP_KEY generieren (sonst: "No application encryption key has been specified")
docker compose -f docker/docker-compose.yml exec php-fpm php artisan key:generate

# 7) Datenbank-Migrationen + Seeds einspielen
docker compose -f docker/docker-compose.yml exec php-fpm php artisan migrate --force
docker compose -f docker/docker-compose.yml exec php-fpm php artisan db:seed --force

# 8) In einem ZWEITEN Terminal: Vue-Dev-Server starten
cd frontend
npm install
npm run dev
```

App-URL: **http://localhost** (nicht :5173 — siehe Troubleshooting).

> ⚠️ **Warum Schritt 3 zwingend ist:** `backend/.env.example` ist auf Host-Mode-Defaults (`DB_HOST=127.0.0.1`) — das passt zum Legacy `start-dev.ps1`-Workflow. Für den empfohlenen Compose-Workflow musst du nach jedem frischen `Copy-Item` exakt zwei Werte ändern. Es gibt **kein** dediziertes Compose-Template (bewusste Entscheidung — weniger Files, ein klarer Stolperstein).

### Routing-Topologie im Compose-Setup

```
Browser → http://localhost (Port 80)
            ↓
        nginx-Container
            ├── /                → proxyt zu host.docker.internal:5173 (Vite-Dev mit HMR)
            └── /api/*           → FastCGI zu php-fpm:9000
                                    ├── /api/v1/*  → REST-Controller
                                    ├── /api/stream → SSE-Stream
                                    └── /api/health → Diagnostics
```

**Wenn `npm run dev` nicht läuft**, gibt nginx 502 für `/` zurück — Vite-Dev im frontend/-Verzeichnis starten.

### `backend/.env` — die zwei Werte, die zwischen Workflows abweichen

`backend/.env.example` ist auf Host-Mode-Defaults. Wenn du Compose nutzt, müssen genau diese **zwei** Zeilen umgestellt werden:

| Variable | Host-Mode (Default in Template) | Compose-Mode | Symptom bei falsch |
|---|---|---|---|
| `DB_HOST` | `127.0.0.1` | `db` | `SQLSTATE[HY000] [2002] Connection refused` bei jedem Request, `php artisan migrate` schlägt fehl |
| `MQTT_HOST` | `127.0.0.1` | `mqtt` | `mqtt-subscriber` Container läuft in Crash-Loop, MQTT-Events kommen nie an |

Alles andere im Template ist für beide Workflows korrekt:
- `MQTT_AUTH_USERNAME=null` / `MQTT_AUTH_PASSWORD=null` (Laravels `env()`-Parser braucht das Wort `null`, leer crasht die php-mqtt/client-Library)
- `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` = `database` (kein Redis-Container im Stack)

**Legacy-Warnung:** falls du eine **alte `.env`** aus einem früheren Stand übernimmst, die noch `SESSION_DRIVER=redis` / `CACHE_STORE=redis` / `QUEUE_CONNECTION=redis` enthält → drei Zeilen auf `database` umstellen. Es gibt keinen Redis-Container im Setup.

Nach jeder `.env`-Änderung **zwingend**:
```powershell
docker compose exec php-fpm php artisan config:clear
docker compose restart php-fpm mqtt-subscriber
```

**Workflow-Übersicht — welcher `.env`-Wert pro Variante:**

| Workflow | `DB_HOST` | `MQTT_HOST` | Template als Startpunkt |
|---|---|---|---|
| Compose (`docker compose up`) | `db` | `mqtt` | `.env.example` + 2 Zeilen umstellen |
| Host (`start-dev.ps1` / `php artisan serve`) | `127.0.0.1` | `127.0.0.1` | `.env.example` (Default-Stand) |
| Pi-Prod (nativ) | `127.0.0.1` | `127.0.0.1` | `.env.raspi.example` (kopiert das Setup-Skript automatisch) |

### Wichtige URLs

| Dienst | URL |
|---|---|
| App (Frontend + Backend, same-origin) | http://localhost |
| API-Health | http://localhost/api/health |
| API-Readiness | http://localhost/api/readiness |
| phpMyAdmin | http://localhost:8090 |
| MySQL (extern) | localhost:3306 |
| MQTT-Broker | localhost:1883 |

---

## Raspberry Pi — Produktions-Setup

### Voraussetzungen
- Raspberry Pi 4 mit Pi OS 64-bit (Bookworm empfohlen — bringt PHP 8.2 als Default)
- Internet für `apt install`
- Standard-User mit `sudo`-Rechten (Standard auf Pi OS)

### Setup-Lauf

```bash
# Repo aufs Pi klonen (Beispiel-Pfad)
git clone https://github.com/lokato-at/lokato-platform.git /home/pi/lokato-platform
cd /home/pi/lokato-platform

# Falls deine Pi-IP nicht 192.168.1.100 sein soll, hier vor dem Lauf anpassen:
export PI_IP=192.168.1.100
export PI_GATEWAY=192.168.1.1
export DB_PASSWORD="ein-starkes-passwort"

chmod +x start-prod-raspi.sh stop-prod-raspi.sh
./start-prod-raspi.sh
```

Das Skript erledigt **idempotent** in dieser Reihenfolge:

1. `apt install` — nginx, php-fpm + Extensions, default-mysql-server (= MariaDB), mosquitto, nodejs, composer
2. Statische IP — Auto-Detect zwischen NetworkManager (nmcli) und dhcpcd
3. MariaDB-User + Datenbank + Schema-Import (`docker/sql/init/01_schema.sql`)
4. Mosquitto-Config aus `docker/mosquitto/config/` nach `/etc/mosquitto/conf.d/lokato.conf`
5. nginx-vhost aus `docker/nginx/prod.conf` nach `/etc/nginx/sites-available/lokato`, Default-Site disablen
6. php-fpm-Pool aus `docker/php-fpm/lokato-pool.conf` nach `/etc/php/X.Y/fpm/pool.d/lokato.conf`, Default-www-Pool nach `.disabled` verschieben
7. systemd-Unit aus `docker/systemd/lokato-mqtt.service` nach `/etc/systemd/system/`
8. Backend deployen nach `/var/www/lokato/backend` via rsync (mit Excludes für vendor/, node_modules/, storage/, bootstrap/cache/*.php), dann `composer install --no-dev`, `php artisan key:generate`, `migrate --force`, `config:cache`, `route:cache`, `view:cache`
9. Frontend bauen (`npm ci && npm run build`), dist nach `/var/www/lokato/frontend/dist`
10. Reload php-fpm + nginx, MQTT-Subscriber starten

Am Ende gibt das Skript ein Summary mit der echten Pi-IP und Bookmark-URLs aus.

### Einmalige Nachbereitung

1. **DB-Passwort umstellen.** Default ist `changeme`. Im `start-prod-raspi.sh` per `DB_PASSWORD=...` mitgeben oder nachträglich anpassen:
   ```bash
   # in /var/www/lokato/backend/.env: DB_PASSWORD=<neues-passwort>
   sudo mysql -e "ALTER USER 'lokato'@'localhost' IDENTIFIED BY '<neues-passwort>';"
   sudo -u www-data php /var/www/lokato/backend/artisan config:cache
   ```

2. **APP_URL prüfen** in `/var/www/lokato/backend/.env`. Standardmäßig steht da der `PI_IP`-Wert; falls dein Pi eine andere IP hat, dort anpassen.

3. **Reboot-Test.** Pi neu starten und prüfen, dass alle Dienste automatisch hochkommen — `systemctl status nginx php8.2-fpm mariadb mosquitto lokato-mqtt`.

### Wenn die Pi-IP sich ändert

Dank Same-Origin steht die IP **nicht** im JS-Bundle. Konsequenz:
- **Kein** `npm run build` nötig.
- Lediglich:
  - statische IP-Config anpassen (`/etc/dhcpcd.conf` oder `nmcli con mod`)
  - Bookmarks der Tablets auf die neue IP umbiegen
  - optional `APP_URL` in `/var/www/lokato/backend/.env` korrigieren (wird nur für absolute URL-Generierung in Mails / Redirects gebraucht, nicht für die App selbst)

### Bookmark-Empfehlung Tablets

| Gerät | Bookmark |
|---|---|
| Admin/Dashboard | `http://192.168.1.100/#/dashboard` |
| Tablet Raum 1 | `http://192.168.1.100/#/tablet/1` |
| Tablet Raum 2 | `http://192.168.1.100/#/tablet/2` |
| ... | ... |

(IP entsprechend ersetzen.)

---

## Server-Sent Events

Es gibt genau **einen** SSE-Endpoint:
```
GET /api/stream                       → Dashboard-Modus (alle Events)
GET /api/stream?room=3&initial=1      → Raumtablet-Modus (gescopet auf Raum 3,
                                         schickt initialen Occupancy-Snapshot)
```

Events:
- `child.moved` — Bewegung eines Kindes
- `room.occupancy.updated` — neuer Belegungs-Snapshot eines Raums
- `room.alert.raised` — Alert (Kapazitäts-Überschreitung etc.)
- `stream.ready` — initial nach Connect
- `stream.draining` — Server beendet die Verbindung (Client soll reconnecten)

Polling-Intervall im Loop: **500 ms**. Idle-Last gegen die DB ist **null**, weil ein Cache-Gate (`App\Support\SseChangeSignal`) die DB-Queries überspringt, solange seit dem letzten Tick kein Scan eingegangen ist. `MqttSubscribeCommand` und `DeviceEventController` rufen nach erfolgreichem Ingest `SseChangeSignal::bump()` auf.

---

## MQTT testen ohne Hardware

### Linux/Bash (auf dem Pi)
```bash
# Subscribe (lauscht parallel zum lokato-mqtt-Subscriber)
mosquitto_sub -h localhost -t "/api/v1/scan" -v

# Publish (simulierter Scan)
mosquitto_pub -h localhost -t "/api/v1/scan" \
  -m '{"device_key":"RaspberryChild02","tracker_uid":"0x80691500004023FDD55DFC23","event_time":"2026-06-04T10:00:00+02:00"}'
```

### Windows/PowerShell (Dev mit Compose)
PowerShell macht `\"`-Escapes brüchig — JSON deshalb in eine **single-quoted Variable** packen, dann übergeben:

```powershell
$payload = '{"device_key":"RaspberryChild02","tracker_uid":"0x80691500004023FDD55DFC23","event_time":"2026-06-04T10:00:00+02:00"}'
docker exec lokato-mosquitto mosquitto_pub -t "/api/v1/scan" -m $payload
```

### Verfügbare Test-Kombinationen aus den Seeds
| Scanner (`device_key`) | Raum | Beispiel-Kind (`tracker_uid`) |
|---|---|---|
| `RaspberryChild01` | Untergeschoss (3) | `0x80691500005023FDD541FC7F` (GELB-HERZ) |
| `RaspberryChild02` | Obergeschoss (2) | `0x80691500004023FDD55DFC23` (SCHWARZ-BLUME) |
| `RaspberryChild03` | Garten (1) | `0x80691500004023FDD525FCA2` (BLAU-BLUME) |

Erwartetes Verhalten:
- `journalctl -u lokato-mqtt -f` (Pi) bzw. `docker compose logs -f mqtt-subscriber` (Dev) zeigt `mqtt_message_processed`
- Tablets/Dashboard zeigen Bewegung sofort (500 ms-Latenz dank Cache-Wakeup)

---

## Cron-Jobs auf dem Pi

> ✅ **Wird automatisch eingerichtet.** `start-prod-raspi.sh` legt seit Phase-4-Ergänzung die vier Crontab-Einträge für `www-data` idempotent an (zwischen Markern, sodass spätere Skript-Läufe oder eigene Einträge erhalten bleiben). Dieser Abschnitt erklärt was läuft und wie du es prüfst.

Es gibt **zwei voneinander unabhängige** Cron-Aufgaben:

### Aufgabe 1: Laravel-Scheduler (steuert den Daily-Reset)

**Was läuft:** Laravel-Scheduler-Definition in `backend/routes/console.php:10-15`:
```php
Schedule::call(function () {
    Artisan::call('children:daily-active-reset');
})->dailyAt('01:00')->timezone(env('APP_TIMEZONE', 'Europe/Vienna'));
```

Der Reset setzt alle `children.is_active` auf `false`. Loggt `daily_reset_finished` nach `storage/logs/cron.log`. Damit Laravel das automatisch triggert, ruft System-Cron **minütlich** `php artisan schedule:run` auf — Laravel selbst entscheidet dann, ob etwas zu tun ist.

### Aufgabe 2: Log-Audit (prüft die Laravel-Logs)

`tools/log_audit/log_audit.py` prüft die vier Log-Dateien (`scan.log`, `cron.log`, `laravel.log`, `sse.log`) auf erwartete Patterns. Wird von `start-prod-raspi.sh` automatisch nach `/var/www/lokato/tools/log_audit/` mitdeployed.

### Die vier installierten Crontab-Einträge

`start-prod-raspi.sh → configure_cron()` schreibt diesen Block in `www-data`s crontab:

```cron
# >>> lokato managed cron block -- do not edit between markers >>>
# Laravel-Scheduler -- triggert routes/console.php (u.a. Daily-Reset 01:00 Vienna)
* * * * * cd /var/www/lokato/backend && /usr/bin/php artisan schedule:run >> /var/log/lokato/scheduler.log 2>&1
# Log-Audit (Daily / Weekly / Cleanup)
10 6 * * * cd /var/www/lokato && /usr/bin/python3 tools/log_audit/log_audit.py check --period daily  --config tools/log_audit/config.json >> /var/log/lokato/log-audit.log 2>&1
20 6 * * 1 cd /var/www/lokato && /usr/bin/python3 tools/log_audit/log_audit.py check --period weekly --config tools/log_audit/config.json >> /var/log/lokato/log-audit.log 2>&1
30 3 * * 0 cd /var/www/lokato && /usr/bin/python3 tools/log_audit/log_audit.py cleanup        --config tools/log_audit/config.json >> /var/log/lokato/log-audit.log 2>&1
# <<< lokato managed cron block <<<
```

Der Block ist durch die Marker `>>> lokato managed cron block …` und `<<< lokato managed cron block <<<` umschlossen. Bei wiederholten Setup-Läufen wird der **Block zwischen den Markern komplett ersetzt** — Crontab-Einträge **außerhalb** der Marker (z. B. manuelle Backup-Jobs) bleiben unangetastet.

### Was die einzelnen Zeilen tun

| Cron-Eintrag | Schedule | Effekt |
|---|---|---|
| `* * * * * schedule:run` | jede Minute | Laravel prüft eigene Schedule-Liste; wenn 01:00 Vienna erreicht → `children:daily-active-reset` |
| `10 6 * * * log_audit.py daily` | täglich 06:10 | letzte 24h durchscannen, Kennzahlen + Auffälligkeiten |
| `20 6 * * 1 log_audit.py weekly` | Montag 06:20 | letzte 7 Tage durchscannen |
| `30 3 * * 0 log_audit.py cleanup` | Sonntag 03:30 | Logs älter als `retention_days` (14 default) löschen |

### Verifikation

```bash
# Aktive Crontab anzeigen
sudo crontab -l -u www-data

# Live-Log des Schedulers (sollte minütlich erscheinen, meist mit "no scheduled commands due")
sudo tail -f /var/log/lokato/scheduler.log

# Manueller Probelauf des Daily-Reset (ohne 01:00 abzuwarten)
sudo -u www-data php /var/www/lokato/backend/artisan children:daily-active-reset
sudo tail /var/www/lokato/backend/storage/logs/cron.log
# Erwartet: daily_reset_started ... daily_reset_finished {"affected_children_count":N,...}

# Manueller Log-Audit-Lauf
cd /var/www/lokato
sudo -u www-data python3 tools/log_audit/log_audit.py check --period daily --config tools/log_audit/config.json
```

Exit-Codes vom Log-Audit:
- `0` — clean
- `1` — Errors oder fehlgeschlagene Daily-Resets gefunden
- `2` — Log-Dateien fehlen (Setup unvollständig)

### Alternative: Laravel `schedule:work` als systemd-Service

Statt minütlichem System-Cron kann man auch einen Daemon laufen lassen. Vorteile: ein Prozess, in `systemctl status` direkt sichtbar. Nachteile: zusätzlicher Daemon nur für die Scheduler-Schleife.

```ini
# /etc/systemd/system/lokato-scheduler.service
[Unit]
Description=Lokato Laravel Scheduler
After=network.target mariadb.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/lokato/backend
ExecStart=/usr/bin/php artisan schedule:work
Restart=always

[Install]
WantedBy=multi-user.target
```

Dann `sudo systemctl enable --now lokato-scheduler`. **Wenn du diese Variante nimmst, lass den `* * * * * schedule:run`-Cron-Eintrag weg** (sonst läuft der Scheduler doppelt). Log-Audit musst du weiterhin via Cron einrichten.

---

## Monitoring & Logs

| Quelle | Befehl |
|---|---|
| MQTT-Subscriber | `journalctl -u lokato-mqtt -f` |
| nginx-Access | `sudo tail -f /var/log/nginx/access.log` |
| nginx-Error | `sudo tail -f /var/log/nginx/error.log` |
| php-fpm | `sudo tail -f /var/log/lokato/php-fpm.log` |
| Laravel | `sudo tail -f /var/www/lokato/backend/storage/logs/laravel.log` |
| Scan-Diagnostik | `sudo tail -f /var/www/lokato/backend/storage/logs/scan.log` |
| SSE-Verbindungen | `sudo tail -f /var/www/lokato/backend/storage/logs/sse.log` |
| Service-Status (alle) | `systemctl status nginx php8.2-fpm mariadb mosquitto lokato-mqtt` |

---

## Befehls-Cheat-Sheet

### Dev (Windows + Compose)

| Was | Befehl |
|---|---|
| Stack starten | `cd docker; docker compose up -d` |
| Stack stoppen | `cd docker; docker compose down` |
| Stack komplett resetten (Volumes weg!) | `cd docker; docker compose down -v` |
| Container-Status | `docker compose ps` |
| Live-Log eines Service | `docker compose logs -f mqtt-subscriber` |
| Composer install (Backend) | `docker compose exec php-fpm composer install` |
| Migrations einspielen | `docker compose exec php-fpm php artisan migrate --force` |
| Demo-Daten seeden | `docker compose exec php-fpm php artisan db:seed --force` |
| DB resetten + frisch seeden | `docker compose exec php-fpm php artisan migrate:fresh --seed --force` |
| Laravel-Cache leeren (nach `.env`-Änderung) | `docker compose exec php-fpm php artisan config:clear` |
| php-fpm + MQTT neu starten | `docker compose restart php-fpm mqtt-subscriber` |
| Laravel REPL | `docker compose exec php-fpm php artisan tinker` |
| Test-Scan publizieren | siehe „MQTT testen ohne Hardware" oben |
| Vue-Dev starten | `cd frontend; npm run dev` |
| Frontend type-check | `cd frontend; npm run type-check` |
| Frontend lint | `cd frontend; npm run lint` |
| Frontend tests | `cd frontend; npm run test:unit` |

### Prod (Raspberry Pi)

| Was | Befehl |
|---|---|
| Setup ausführen (idempotent) | `./start-prod-raspi.sh` |
| Nur MQTT stoppen | `./stop-prod-raspi.sh` |
| Alles stoppen | `./stop-prod-raspi.sh --full` |
| Service-Status (alle) | `systemctl status nginx php8.2-fpm mariadb mosquitto lokato-mqtt` |
| Live-Log MQTT-Subscriber | `journalctl -u lokato-mqtt -f` |
| Aktuelle Pi-IP | `hostname -I` |
| Laravel-Logs live | `sudo tail -f /var/www/lokato/backend/storage/logs/{scan,sse,laravel,cron}.log` |
| Manueller Daily-Reset | `sudo -u www-data php /var/www/lokato/backend/artisan children:daily-active-reset` |
| Log-Audit jetzt | `cd /var/www/lokato && python3 tools/log_audit/log_audit.py check --period daily --config tools/log_audit/config.json` |
| Backend-`.env` editieren | `sudo nano /var/www/lokato/backend/.env` |
| Frontend nochmal deployen | `./start-prod-raspi.sh` (idempotent — überspringt fertige Schritte) |

---

## Troubleshooting

Die vier häufigsten Stolpersteine, die in dieser Session aufgetaucht sind.

### 🔴 1. Tablets erreichen den Pi nicht im WLAN

**Symptom:** Browser auf dem Tablet zeigt „Diese Seite kann nicht angezeigt werden" beim Aufruf von `http://<pi-ip>/`.

**Diagnose:**
```bash
# Auf dem Pi:
hostname -I                    # echte IP herausfinden
sudo ufw status                # firewall aktiv?
sudo systemctl status nginx    # nginx läuft?
sudo ss -tlnp | grep :80       # nginx hört auf Port 80?

# Vom Tablet aus (im selben WLAN):
ping <pi-ip>                   # Layer-3-Erreichbarkeit
```

**Lösungen je nach Ursache:**
- **Pi hat eine andere IP** als im Bookmark hinterlegt → Bookmark aktualisieren ODER statische IP setzen (siehe Szenario 4).
- **Pi und Tablet in unterschiedlichen Subnetzen** (z. B. Pi am LAN, Tablet im Gast-WLAN) → Tablets ins selbe SSID/VLAN bringen.
- **WLAN-Router isoliert Clients** (AP-Isolation aktiv) → Router-Config: „Client-Isolation" oder „AP-Isolation" abschalten.
- **firewall (`ufw`) blockt Port 80** → `sudo ufw allow 80/tcp`.
- **Pi-IP korrekt, aber 502 von nginx** → siehe Szenario 2 (php-fpm) oder Szenario 5 (MySQL).

### 🔴 2. MQTT-Subscriber down / Crash-Loop

**Symptom:** Scans werden nicht verarbeitet; im UI keine Bewegungs-Updates.

**Diagnose (Pi):**
```bash
systemctl status lokato-mqtt
journalctl -u lokato-mqtt -n 30 --no-pager
```

**Diagnose (Compose):**
```bash
docker compose ps mqtt-subscriber          # "Restarting (1)" = Crash-Loop
docker compose logs --tail 20 mqtt-subscriber
```

**Häufige Ursachen:**
- **`MQTT_AUTH_USERNAME=` (leer) in `.env`** → php-mqtt/client crasht mit „The username may not consist of white space only". **Fix:** `MQTT_AUTH_USERNAME=null` und `MQTT_AUTH_PASSWORD=null` (explizit `null`, nicht leer). Nach Änderung: `config:clear` + `restart mqtt-subscriber`.
- **`MQTT_HOST=127.0.0.1` im Compose** → der Subscriber im Container kann den Mosquitto-Container so nicht erreichen. **Fix:** `MQTT_HOST=mqtt` (Compose-Service-Name).
- **Falsches Topic** → Publisher und `MQTT_TOPIC_SCAN` müssen exakt übereinstimmen — `/api/v1/scan` ≠ `api/v1/scan` (Slash beachten).
- **Mosquitto down** → `systemctl status mosquitto` bzw. `docker compose ps mqtt`.

**Manueller Test:**
```bash
# Pi:
mosquitto_sub -h localhost -t "/api/v1/scan" -v   # passive Mitlesung
# In einem zweiten Terminal Test publizieren — siehe „MQTT testen ohne Hardware"
```

### 🔴 3. MySQL `Connection refused` / `SQLSTATE[HY000] [2002]`

**Symptom:** Bei jedem API-Request 500-Fehler; `php artisan migrate` schlägt fehl.

**Diagnose:**
```bash
# Compose:
docker compose ps db                       # "(healthy)"?
docker compose exec php-fpm php artisan tinker --execute "DB::connection()->getPdo();"

# Pi:
systemctl status mariadb
sudo -u www-data php /var/www/lokato/backend/artisan migrate:status
```

**Häufige Ursachen:**
- **`DB_HOST=127.0.0.1` im Compose** → Container sieht sein eigenes 127.0.0.1 (kein MySQL drin). **Fix:** `DB_HOST=db` (Compose-Service-Name).
- **`SESSION_DRIVER=redis` o.ä. im Compose, aber kein Redis im Stack** → Cache-Tabelle wird nicht angelegt; Session-Writes failen. **Fix:** `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`.
- **Pi: MariaDB nach Reboot nicht hochgekommen** → `sudo systemctl restart mariadb`. Logs in `/var/log/mysql/error.log`.
- **Pi: Falsches DB-Passwort in `.env`** → testen mit `mysql -u lokato -p`. Korrigieren über `ALTER USER 'lokato'@'localhost' IDENTIFIED BY '<neu>';` und `.env` synchron halten.

**Nach jeder `.env`-Änderung zwingend:**
```bash
# Compose:
docker compose exec php-fpm php artisan config:clear
docker compose restart php-fpm mqtt-subscriber

# Pi:
sudo -u www-data php /var/www/lokato/backend/artisan config:clear
sudo systemctl restart php8.2-fpm lokato-mqtt
```

### 🔴 4. Pi hat eine neue IP bekommen

**Symptom:** Tablets erreichen den Pi nicht mehr unter der gebookmarken URL.

**Vorab: die gute Nachricht** — dank Same-Origin-Routing steht die Pi-IP **nicht** im JS-Bundle. Du musst **nichts neu bauen oder deployen**.

**Welcher Netz-Manager läuft?**
```bash
systemctl is-active NetworkManager dhcpcd
```

**Variante A: NetworkManager (Bookworm Default)**
```bash
sudo nmcli con show                                      # Connections auflisten
sudo nmcli con mod "Wired connection 1" \
  ipv4.addresses 192.168.1.100/24 \
  ipv4.gateway 192.168.1.1 \
  ipv4.dns "192.168.1.1 1.1.1.1" \
  ipv4.method manual
sudo nmcli con up "Wired connection 1"
```

**Variante B: dhcpcd (ältere Pi-OS-Versionen)**

In `/etc/dhcpcd.conf` ans Ende:
```
interface eth0
static ip_address=192.168.1.100/24
static routers=192.168.1.1
static domain_name_servers=192.168.1.1 1.1.1.1
```
Dann `sudo systemctl restart dhcpcd`.

**Nach dem IP-Wechsel zu erledigen:**
1. Bookmark der Tablets auf neue IP umstellen.
2. Optional: `APP_URL` in `/var/www/lokato/backend/.env` anpassen (kosmetisch, betrifft nur Mail-Templates u. ä.).
3. `start-prod-raspi.sh` muss **nicht** erneut laufen.

---

### Weitere Stolpersteine

#### nginx 502 unter `/`
- **Dev**: Vite-Dev läuft nicht → `npm run dev` im `frontend/`.
- **Prod**: nicht möglich, weil `/` statisch ausgeliefert wird. Frontend wurde nicht gebaut: `start-prod-raspi.sh` nochmal laufen lassen.

#### nginx 502 für `/api/...`
- php-fpm down. Compose: `docker compose ps php-fpm`. Pi: `systemctl status php8.2-fpm`.

#### SSE bekommt keine Events
- Cache-Wakeup-Kette prüfen: Scan kommt → `mqtt_message_processed` in `scan.log` → `SseChangeSignal::bump()` → SSE-Loop pollt. Wenn `scan.log` keinen `mqtt_message_processed` zeigt, liegt das Problem im Subscriber (Szenario 2), nicht in SSE.

#### "Class BoostServiceProvider not found" beim Pi-Deploy
- Stale `bootstrap/cache/*.php` aus früherem Dev-Build. `start-prod-raspi.sh` räumt das auf; bei manuellem Deploy: `sudo rm /var/www/lokato/backend/bootstrap/cache/*.php`, dann `php artisan config:cache`.

#### 403 Forbidden für `/node_modules/.vite/…` im Dev
- Bekanntes Problem mit dem deny-Block für versteckte Dateien in nginx. In `docker/nginx/dev.conf` ist der Block bereits entfernt; falls trotzdem 403: `docker compose restart nginx`.

#### PowerShell-Quoting bei MQTT-Test schlägt fehl
- Symptom: `MQTT payload is not valid JSON: Syntax error`. PowerShell mangelt `\"`-Escapes. **Fix:** JSON in eine single-quoted Variable packen — siehe „MQTT testen ohne Hardware".

---

## Skripte zusammengefasst

| Skript | Zweck |
|---|---|
| `start-prod-raspi.sh` | Pi-Prod: Komplett-Setup (apt-Install, statische IP, Deploy, systemd) |
| `stop-prod-raspi.sh` | Stoppt `lokato-mqtt` (default) bzw. alle Lokato-Dienste mit `--full` |
| `start-dev.ps1` | Win-Dev: Komfort-Wrapper. **Veraltet** für das neue Compose-Setup — bevorzuge `docker compose up` + `npm run dev` |
| `initial-setup.ps1` | Erstes Windows-Setup (winget-Installs etc.) |

---

## Was NICHT angefasst werden soll

- `backend/app/Services/ScanIngestService.php` ist die zentrale Scan-Verarbeitungslogik. Änderungen dort außerhalb des Phase-2/3-Scopes ausdrücklich nicht erwünscht.
- Fachliche Geschäftsregeln in `MovementLog`, `ChildLocation`, `Alert`-Modellen.

Siehe `CLAUDE.md` für die vollständige Architekturentscheidungs-Übersicht und `BETREUUNG.md` für die Soll-Ist-Analyse gegen die ursprüngliche Architektur-Analyse.
