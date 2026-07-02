# Production — Raspberry Pi Setup

Pi nativ (kein Docker). `start-prod-raspi.sh` ist idempotent und macht das komplette Setup.

---

## Voraussetzungen

- Raspberry Pi 4 mit Pi OS 64-bit (Bookworm empfohlen — PHP 8.2 als Default)
- Internet für `apt install`
- Standard-User mit `sudo`-Rechten

## Setup-Lauf

```bash
# Repo aufs Pi klonen
git clone https://github.com/lokato-at/lokato-platform.git /home/pi/lokato-platform
cd /home/pi/lokato-platform

chmod +x *.sh

# (Einmalig) statische IP setzen — separates Skript, kann SSH kurz kappen:
PI_IP=192.168.1.100 ./setup-network-raspi.sh

# Deploy — zeigt am Anfang alle Optionen, lässt dich die .env editieren,
# bevor DB + Migration darauf aufsetzen. Idempotent.
DB_PASSWORD="ein-starkes-passwort" \
  SEED_ADMIN=1 ADMIN_USER_PASSWORD="admin-passwort" \
  SEED_MASTERDATA=1 \
  ./start-prod-raspi.sh
```

Nützliche Flags/Vars: `INSTALL_DEPS=0` (apt überspringen), `SETUP_LOG_AUDIT=0` (Log-Audit weglassen), `-y` (keine Rückfragen), `RESET_ENV=1` (bestehende `.env` aus Template neu). Das Skript ist idempotent — mehrfache Läufe sind sicher.

## Was das Skript macht (in dieser Reihenfolge)

Am Anfang zeigt es eine **Options-Übersicht** und fragt nach Bestätigung.

1. **Preflight** — Repo-Vollständigkeit, richtiger Ort, freier Platz
2. **apt install** — nginx, php-fpm, MariaDB, mosquitto, nodejs, composer, rsync (`INSTALL_DEPS=0` überspringt)
3. **.env-Review** — legt `/var/www/lokato/backend/.env` aus dem Template an und lässt dich sie **editieren** (DB_PASSWORD, APP_URL …), **bevor** die DB darauf aufsetzt. Eine bestehende `.env` wird **nie ohne Zustimmung** überschrieben (nur bei interaktivem Ja oder `RESET_ENV=1`, dann mit Backup).
4. **MariaDB** — DB + User (`@localhost` **und** `@127.0.0.1`), Passwort aus der `.env`. Schema baut ausschließlich `migrate` (kein SQL-Import).
5. **Mosquitto / nginx / php-fpm / systemd-Unit** — Configs aus `docker/…` installieren
6. **Backend deployen** — rsync (vendor/.env ausgenommen), vendor/Cache-Reset falls nötig, `composer install --no-dev`, `key:generate` (nur wenn leer), **DB-Login-Test**, `migrate --force`, `config/route/view:cache`, optional Seeding (`SEED_ADMIN` / `SEED_MASTERDATA`)
7. **Frontend bauen** (`npm ci && npm run build`) → `/var/www/lokato/frontend/dist/`
8. **Log-Audit-Tool** (nur `SETUP_LOG_AUDIT=1`) + **Cron** (Laravel-Scheduler immer; Log-Audit-Jobs nur bei `SETUP_LOG_AUDIT=1`)
9. **Reload + Restart** aller Services

> **Statische IP** ist **nicht** Teil davon (Einmal-Setup, kann SSH kurz kappen) — separates Skript: `PI_IP=192.168.1.100 ./setup-network-raspi.sh`.

Am Ende: Summary-Box mit echter Pi-IP und Bookmark-URLs.

## Smoketest nach Setup

```bash
# Alle Services aktiv?
systemctl status nginx php8.2-fpm mariadb mosquitto lokato-mqtt --no-pager

# API-Health
curl http://localhost/api/health

# Subscriber lebt?
journalctl -u lokato-mqtt --since "1 min ago" | grep "Subscribed"
```

Erwartete Ausgaben:
- alle 5 Services **active (running)**
- `/api/health` → `{"status":"ok",...}`
- `journalctl` → mind. `Subscribed. Waiting for messages on topic: /api/v1/scan`

## Admin-User für Erststart

Ohne User ist `/admin` leer (`/api/v1/admin/*` nicht erreichbar). Standardmäßig seedet das Skript **keinen** User — außer du gibst `SEED_ADMIN=1`. Beide Wege unten laufen über den **Seeder**; **kein `tinker`** (auf dem Pi läuft PsySH headless nicht zuverlässig — PsySH kann nicht nach `$HOME` schreiben, siehe TROUBLESHOOTING.md).

### Variante A: beim Deploy automatisch (empfohlen)

```bash
SEED_ADMIN=1 ADMIN_USER_EMAIL=admin@hort.local ADMIN_USER_PASSWORD='DEIN_PASSWORT' \
  ./start-prod-raspi.sh
```

`SEED_ADMIN=1` ruft am Ende den `AdminUserSeeder` (idempotent, `updateOrCreate` auf der E-Mail). Ohne `ADMIN_USER_PASSWORD` generiert der Seeder eins und druckt es ins Log.

### Variante B: AdminUserSeeder manuell (idempotent)

Der `AdminUserSeeder` liest `ADMIN_USER_EMAIL` und `ADMIN_USER_PASSWORD` aus dem Environment und macht `updateOrCreate` auf der E-Mail — d.h. mehrfaches Ausführen ist sicher (User bleibt, Passwort wird auf das aktuelle env-Var gesetzt).

```bash
ADMIN_USER_EMAIL=admin@hort.local \
ADMIN_USER_PASSWORD=DEIN_PASSWORT \
  sudo -E -u www-data php /var/www/lokato/backend/artisan db:seed \
  --class=AdminUserSeeder --force
```

Das `-E` an `sudo` ist wichtig — sonst werden die Env-Vars beim User-Switch verworfen und der Seeder generiert ein Random-Passwort (das er ins stdout schreibt — auch ein gangbarer Weg, wenn du es einmalig brauchst).

### Verifikation

```bash
curl -s http://localhost/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@hort.local","password":"DEIN_PASSWORT"}' | head
```

Erwartet: JSON-Response mit `token` und `user`-Block.

### Passwort später ändern

Variante A oder B mit anderen Env-Vars erneut ausführen — `updateOrCreate` setzt das Passwort für die E-Mail neu. Kein `tinker` nötig.

## Stammdaten beim Erststart (Räume + Devices)

Wie der Admin-User seedet `start-prod-raspi.sh` **bewusst keine** Stammdaten. Nach `migrate` sind `rooms`, `devices` und `children` **leer** — die Tablet-Ansicht (`/#/tablet/1`) liefert dann **404**, weil Raum 1 nicht existiert (Route-Model-Binding in `RoomsController::occupancy` + `findOrFail` im `SseStreamController` schlagen beide fehl).

Du brauchst mindestens **Räume** (für die Anzeige) und **Devices** (Scanner→Raum-Mapping für Scans). **Nicht** den `ChildSeeder` — der legt Dev-Fixtures (Fake-Kinder wie „LILA-AUTO") an. Auch **nicht** `db:seed` ohne `--class` (das ruft `DatabaseSeeder` = alle Seeder inkl. Kinder).

### Variante A: beim Deploy automatisch (empfohlen für den Erststart)

```bash
SEED_MASTERDATA=1 SEED_ADMIN=1 ADMIN_USER_PASSWORD='DEIN_PASSWORT' \
  DB_PASSWORD="$DB_PASSWORD" ./start-prod-raspi.sh
```

`SEED_MASTERDATA=1` seedet `RoomSeeder` + `DeviceSeeder` — **nur wenn die `rooms`-Tabelle leer ist** (die Seeder nutzen `insert()`/`create()` und sind nicht idempotent; der Count-Guard im Skript verhindert Duplikate bei wiederholten Läufen).

### Variante B: manuell

```bash
sudo -u www-data php /var/www/lokato/backend/artisan db:seed --class=RoomSeeder   --force
sudo -u www-data php /var/www/lokato/backend/artisan db:seed --class=DeviceSeeder --force
```

`RoomSeeder` legt Garten (id 1), Obergeschoss (id 2), Untergeschoss (id 3) an — passend zu den Tablet-Bookmarks `/#/tablet/1..3`. `DeviceSeeder` ordnet die Scanner `RaspberryChild01..03` diesen Räumen zu und **muss nach** `RoomSeeder` laufen (er sucht die Räume per Name).

> ⚠️ Beide Seeder sind **nicht idempotent** — nur auf leeren Tabellen ausführen, sonst Duplikate. Zum Zurücksetzen (nur ohne echte Bewegungsdaten!):
> ```bash
> sudo mysql lokato_db -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE rooms; TRUNCATE devices; SET FOREIGN_KEY_CHECKS=1;"
> ```
> `TRUNCATE` setzt auch den AUTO_INCREMENT zurück, damit Garten wieder id 1 bekommt. Danach neu seeden.

### Variante C: über die Admin-UI (prod-sauber, eigene Raum-Namen)

Login als Admin → Räume/Devices anlegen. Die IDs zählen dann vom AUTO_INCREMENT hoch (nicht zwingend 1/2/3) → Tablet-Bookmarks entsprechend setzen (`/#/tablet/<echte-id>`).

### Verifikation

```bash
curl -s http://localhost/api/v1/rooms; echo                       # Räume + IDs
curl -s -w '\n[%{http_code}]\n' http://localhost/api/v1/rooms/1/occupancy
```
Erwartet: Liste der Räume, occupancy → 200. Danach lädt `/#/tablet/1`. (`bash pi-doctor.sh` zeigt den Gesamt-Status.)

## Pi-Befehls-Cheat-Sheet

| Was | Befehl |
|---|---|
| Setup ausführen | `./start-prod-raspi.sh` |
| Statische IP setzen (einmalig) | `PI_IP=192.168.1.100 ./setup-network-raspi.sh` |
| MQTT stoppen | `./stop-prod-raspi.sh` |
| Alles stoppen | `./stop-prod-raspi.sh --full` |
| Service-Status (alle) | `systemctl status nginx php8.2-fpm mariadb mosquitto lokato-mqtt` |
| Live-Log MQTT | `journalctl -u lokato-mqtt -f` |
| Aktuelle Pi-IP | `hostname -I` |
| Laravel-Logs live | `sudo tail -f /var/www/lokato/backend/storage/logs/{scan,sse,laravel,cron}.log` |
| Manueller Daily-Reset | `sudo -u www-data php /var/www/lokato/backend/artisan children:daily-active-reset` |
| Log-Audit jetzt | `cd /var/www/lokato && python3 tools/log_audit/log_audit.py check --period daily --config tools/log_audit/config.json` |
| Backend-`.env` | `sudo nano /var/www/lokato/backend/.env` |
| Backend re-deployen | `./start-prod-raspi.sh` (idempotent) |
| Stammdaten seeden | `sudo -u www-data php …/artisan db:seed --class=RoomSeeder --force` (dann `DeviceSeeder`) |
| Healthcheck (alles) | `bash pi-doctor.sh` |
| Pre-Deploy-Check (Dev) | `bash predeploy-check.sh` |

## Test-Scan auf dem Pi

```bash
mosquitto_pub -h localhost -t "/api/v1/scan" \
  -m '{"device_key":"RaspberryChild02","tracker_uid":"0x80691500004023FDD55DFC23","event_time":"2026-06-15T10:00:00+02:00"}'
```

→ `journalctl -u lokato-mqtt -f` zeigt `mqtt_message_processed`, Tablets/Dashboard updaten binnen 500 ms.

## Tablet-Bookmarks

| Gerät | URL |
|---|---|
| Admin/Dashboard | `http://192.168.1.100/#/dashboard` |
| Tablet Raum 1 | `http://192.168.1.100/#/tablet/1` oder `?id=1` |
| Tablet Raum 2 | `http://192.168.1.100/#/tablet/2` |
| Tablet Raum 3 | `http://192.168.1.100/#/tablet/3` |

(IP entsprechend ersetzen.)

## Pi-IP wechseln

Same-Origin-Routing → IP ist **nicht** im JS-Bundle. Kein Rebuild nötig:

```bash
# NetworkManager
sudo nmcli con mod "Wired connection 1" \
  ipv4.addresses 192.168.1.99/24 \
  ipv4.method manual
sudo nmcli con up "Wired connection 1"

# ODER dhcpcd
sudo nano /etc/dhcpcd.conf   # ip_address-Zeile anpassen
sudo systemctl restart dhcpcd
```

Danach Tablet-Bookmarks auf neue IP umstellen. Fertig.

## Production-Test-Checkliste vor Hort-Einsatz

- [ ] `systemctl status` zeigt alle 5 Dienste active
- [ ] `/api/health` + `/api/readiness` antworten 200
- [ ] Test-Scan löst `MovementLog`-Eintrag aus
- [ ] Tablet im Hort-WLAN erreicht `http://<pi-ip>/#/tablet/1`
- [ ] Dashboard auf Admin-Gerät zeigt alle Räume
- [ ] `daily_reset_finished` taucht in `cron.log` täglich auf
- [ ] `log_audit.py check --period daily` → Exit 0
- [ ] Reboot-Test: alle Dienste kommen automatisch hoch
- [ ] DB-Backup-Strategie geklärt
- [ ] Hort-Personal kennt die Tablet-Bookmarks
- [ ] hinterlegte Kinder mit Hort-Liste abgeglichen
