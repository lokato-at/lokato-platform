# Troubleshooting

Die häufigsten Stolpersteine, die in der Praxis aufgetaucht sind. Siehe auch `CLAUDE.md` für `.env`-Quirks.

---

## 🔴 Tablets erreichen den Pi nicht im WLAN

**Symptom:** Browser auf dem Tablet zeigt „Diese Seite kann nicht angezeigt werden" beim Aufruf von `http://<pi-ip>/`.

### Diagnose

```bash
# Auf dem Pi
hostname -I                    # echte IP herausfinden
sudo systemctl status nginx    # nginx läuft?
sudo ss -tlnp | grep :80       # nginx hört auf Port 80?

# Vom Tablet (im selben WLAN)
ping <pi-ip>                   # Layer-3-Erreichbarkeit
```

### Lösungen

- **Pi hat andere IP** → Bookmark aktualisieren oder statische IP setzen (siehe Szenario 4)
- **Tablet in anderem Subnetz** (Gast-WLAN vs LAN) → ins selbe SSID bringen
- **Router-Client-Isolation aktiv** → in Router-Config abschalten
- **Firewall blockt Port 80** → `sudo ufw allow 80/tcp`
- **IP korrekt, aber 502** → siehe Szenario 2 (php-fpm) oder Szenario 3 (MySQL)

---

## 🔴 MQTT-Subscriber down / Crash-Loop

**Symptom:** Scans werden nicht verarbeitet; UI bekommt keine Bewegungs-Updates.

### Diagnose (Pi)

```bash
systemctl status lokato-mqtt
journalctl -u lokato-mqtt -n 30 --no-pager
```

### Diagnose (Dev/Compose)

```bash
docker compose ps mqtt-subscriber          # "Restarting (1)" = Crash-Loop
docker compose logs --tail 20 mqtt-subscriber
```

### Häufige Ursachen

- **`MQTT_AUTH_USERNAME=` (leer) in `.env`** → php-mqtt/client crasht mit „The username may not consist of white space only". **Fix:** `MQTT_AUTH_USERNAME=null` und `MQTT_AUTH_PASSWORD=null` (explizit `null`!). Danach `config:clear` + restart.
- **`MQTT_AUTO_RECONNECT_ENABLED=true` mit clean_session=true** → „Automatic reconnects cannot be used together with the clean session flag". **Fix:** `MQTT_AUTO_RECONNECT_ENABLED=false` setzen (systemd-Restart fängt Netz-Drops auf).
- **`MQTT_HOST=127.0.0.1` im Compose** → Subscriber im Container kann den Mosquitto-Container nicht erreichen. **Fix:** `MQTT_HOST=mqtt`.
- **Falsches Topic** → Publisher und `MQTT_TOPIC_SCAN` müssen exakt übereinstimmen (`/api/v1/scan` ≠ `api/v1/scan`).
- **Mosquitto down** → `systemctl status mosquitto` / `docker compose ps mqtt`.

### Manueller Test

```bash
# Passive Mitlesung
mosquitto_sub -h localhost -t "/api/v1/scan" -v

# In zweitem Terminal Test-Scan publizieren (siehe DEVELOPMENT.md / PRODUCTION.md)
```

---

## 🔴 MySQL `Connection refused` / `SQLSTATE[HY000] [2002]`

**Symptom:** Jeder API-Request mit 500-Fehler; `php artisan migrate` schlägt fehl.

### Diagnose

```bash
# Compose
docker compose ps db                       # "(healthy)"?
docker compose exec php-fpm php artisan tinker --execute "DB::connection()->getPdo();"

# Pi
systemctl status mariadb
sudo -u www-data php /var/www/lokato/backend/artisan migrate:status
```

### Häufige Ursachen

- **`DB_HOST=127.0.0.1` im Compose** → Container sieht sein eigenes 127.0.0.1 (kein MySQL drin). **Fix:** `DB_HOST=db`.
- **`SESSION_DRIVER=redis` o.ä. im Compose** → kein Redis-Container im Stack. **Fix:** `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`.
- **Pi: MariaDB nach Reboot nicht hochgekommen** → `sudo systemctl restart mariadb`. Logs in `/var/log/mysql/error.log`.
- **Pi: Falsches DB-Passwort in `.env`** → testen mit `mysql -u lokato -p`. Korrigieren via `ALTER USER 'lokato'@'localhost' IDENTIFIED BY '<neu>';` und `.env` synchron halten.

### Pflicht nach jeder `.env`-Änderung

```bash
# Compose
docker compose exec php-fpm php artisan config:clear
docker compose restart php-fpm mqtt-subscriber

# Pi
sudo -u www-data php /var/www/lokato/backend/artisan config:clear
sudo systemctl restart php8.2-fpm lokato-mqtt
```

---

## 🔴 Pi hat eine neue IP bekommen

**Symptom:** Tablets erreichen den Pi nicht mehr unter der gebookmarken URL.

**Vorab:** dank Same-Origin steht die Pi-IP **nicht** im JS-Bundle. **Kein** `npm run build` nötig.

### Welcher Netz-Manager läuft?

```bash
systemctl is-active NetworkManager dhcpcd
```

### Variante A — NetworkManager (Bookworm Default)

```bash
sudo nmcli con show
sudo nmcli con mod "Wired connection 1" \
  ipv4.addresses 192.168.1.100/24 \
  ipv4.gateway 192.168.1.1 \
  ipv4.dns "192.168.1.1 1.1.1.1" \
  ipv4.method manual
sudo nmcli con up "Wired connection 1"
```

### Variante B — dhcpcd

`/etc/dhcpcd.conf` am Ende:
```
interface eth0
static ip_address=192.168.1.100/24
static routers=192.168.1.1
static domain_name_servers=192.168.1.1 1.1.1.1
```
```bash
sudo systemctl restart dhcpcd
```

### Nach dem IP-Wechsel

1. Bookmark der Tablets auf neue IP umstellen
2. Optional: `APP_URL` in `/var/www/lokato/backend/.env` anpassen (kosmetisch)
3. `start-prod-raspi.sh` muss **nicht** erneut laufen

---

## Weitere Stolpersteine

### nginx 502 unter `/`
- **Dev**: Vite-Dev läuft nicht → `npm run dev` im `frontend/`
- **Prod**: nur möglich wenn Frontend nicht gebaut wurde → `start-prod-raspi.sh` erneut

### nginx 502 für `/api/...`
- php-fpm down. Compose: `docker compose ps php-fpm`. Pi: `systemctl status php8.2-fpm`

### SSE bekommt keine Events
Kette prüfen: Scan → `mqtt_message_processed` in `scan.log` → `SseChangeSignal::bump()` → SSE-Loop pollt. Wenn `scan.log` keinen `mqtt_message_processed` zeigt, liegt das Problem im Subscriber (Szenario 2).

### „Class BoostServiceProvider not found" beim Pi-Deploy
Stale `bootstrap/cache/*.php` aus früherem Dev-Build. `start-prod-raspi.sh` räumt das auf; manuell: `sudo rm /var/www/lokato/backend/bootstrap/cache/*.php`, dann `php artisan config:cache`.

### 403 Forbidden für `/node_modules/.vite/…` im Dev
Bekannter Bug mit deny-Block für versteckte Dateien in nginx. In `docker/nginx/dev.conf` ist der Block entfernt. Falls trotzdem 403: `docker compose restart nginx`.

### PowerShell-Quoting bei MQTT-Test schlägt fehl
„MQTT payload is not valid JSON: Syntax error". PowerShell mangelt `\"`-Escapes. **Fix:** JSON in single-quoted Variable packen (siehe DEVELOPMENT.md).

### „Missing required env: DB_HOST, …" nach `config:cache`
`env()` außerhalb von `config/*.php` liefert `null` nach `config:cache`. Code muss `config()` statt `env()` nutzen. Ist im `AppServiceProvider` und Routes seit der Session korrigiert.
