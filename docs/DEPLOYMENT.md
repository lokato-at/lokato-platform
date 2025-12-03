# 🏭 Produktions-Deployment

Dieses Dokument beschreibt ein **einfaches, reproduzierbares Deployment** für Backend (Laravel), Frontend (Vue/Vite) und MySQL (Docker). Ziel ist eine schlanke produktive Umgebung ohne lokale Dev-Tools.

## ✅ Voraussetzungen

* Linux-Server mit Docker + Docker Compose (v2)
* Systemdienst für PHP (z. B. **php-fpm 8.2+**)
* Webserver/Reverse Proxy (z. B. **Nginx**)
* Node.js **LTS** (nur zum Bauen des Frontends)
* Git

> **Tipp:** Alle Schritte können per CI/CD ausgeführt werden. Die Kommandos hier orientieren sich an einem manuellen Server-Setup.

---

## 1) Datenbank mit Docker starten

```bash
cd docker
# Persistenten MySQL-Container starten
DOCKER_DEFAULT_PLATFORM=linux/amd64 docker compose up -d
```

* Container: `lokato-mysql`
* Ports: **3306** (MySQL) + **8090** (phpMyAdmin)
* Erstinitialisierung: SQL-Skripte in `docker/sql/init/` werden automatisch eingespielt.

---

## 2) Backend deployen (Laravel)

```bash
cd backend
cp .env.example .env  # einmalig
# .env für Produktion anpassen (APP_URL, DB_HOST=db, APP_DEBUG=false etc.)

composer install --no-dev --optimize-autoloader
php artisan key:generate             # falls APP_KEY leer
php artisan migrate --force          # führt Migrationen gegen die MySQL-Instanz aus
php artisan storage:link             # Symlink für Uploads
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

* PHP-FPM-Pool auf den Code zeigen lassen (z. B. `/var/www/lokato/backend/public`).
* Nginx/Apache als Reverse Proxy auf **:8001** oder den PHP-FPM-Socket konfigurieren.
* Sicherstellen, dass `storage/` und `bootstrap/cache/` Schreibrechte für den Webserver haben.

---

## 3) Frontend bauen und ausliefern

```bash
cd frontend
npm ci
npm run build       # legt /dist an
```

* Den Inhalt von `frontend/dist/` als **statische Assets** über Nginx/Apache ausliefern.
* Backend-API-URL in der Build-Umgebung per **Vite Env** setzen (z. B. `VITE_API_BASE_URL=https://api.deine-domain.tld`).
* Beispiel-Nginx-Location für das Frontend:

```nginx
server {
    listen 80;
    server_name app.deine-domain.tld;
    root /var/www/lokato/frontend/dist;
    try_files $uri $uri/ /index.html;
}
```

---

## 4) Prozess-Management & Monitoring

* **Supervisor/Systemd** für `php-fpm` und den Webserver nutzen.
* Backups: Docker-Volume `lokato_db_data` regelmäßig sichern.
* **Logs**
  * Backend: `storage/logs/laravel.log` und `storage/logs/scan.log`
  * Webserver/Nginx-Access + Error Logs

---

## 5) Schnell-Check nach dem Deployment

| Check                     | Befehl/URL                                   |
| ------------------------- | -------------------------------------------- |
| DB-Container läuft        | `docker ps`                                  |
| Backend erreichbar        | `curl -I https://api.deine-domain.tld/ping`  |
| Migrationen ok            | `php artisan migrate:status`                 |
| Frontend ausgeliefert     | Browser: `https://app.deine-domain.tld`      |
| API ↔ Frontend            | Netzwerkanfragen im Browser-Devtools prüfen  |

---

## 6) Updates einspielen (Rolling)

```bash
# 1. Code aktualisieren
git pull

# 2. Backend neu optimieren
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 3. Frontend neu bauen
npm ci
npm run build

# 4. Webserver/Cache neustarten falls nötig
sudo systemctl reload nginx
```

Mit diesen Schritten lässt sich das Lokato-Stack reproduzierbar und ohne Dev-Abhängigkeiten produktiv betreiben.

---

## 7) CI/CD-Automatisierung (GitHub Actions)

Dieses Repository enthält eine vorkonfigurierte GitHub-Action (`.github/workflows/deploy.yml`), die einen einfachen Build-und-Deploy-Prozess ausführt:

1. **Build:** Composer-Install (ohne Dev) fürs Backend, `npm ci` + `npm run build` fürs Frontend.
2. **Artifact:** Paketiert Backend + Frontend-Build + Docs als `lokato-release.tar.gz`.
3. **Transfer:** Lädt das Archiv via SSH/SCP auf den Zielserver (`DEPLOY_PATH/releases`).
4. **Deploy auf Server:** Entpackt in einen Zeitstempel-Release, verlinkt `.env` + `storage/`, führt `php artisan migrate --force` und Cache-Optimierungen aus und setzt `current`-Symlink auf den neuen Release.

### Benötigte Server-Voraussetzungen

* SSH-Zugriff (Key-basiert) mit Schreibrechten auf `DEPLOY_PATH` (z. B. `/var/www/lokato`).
* PHP (CLI) + benötigte Extensions, damit `php artisan` auf dem Server läuft.
* MySQL-Zugriff (z. B. über den bestehenden Docker-Container) und eine gültige `.env`.
* Webserver/Proxy zeigt auf `${DEPLOY_PATH}/current/frontend/dist` (statische Assets) und `${DEPLOY_PATH}/current/backend/public` (API per PHP-FPM).

### Einmalige Einrichtung auf dem Server

```bash
DEPLOY_PATH=/var/www/lokato
mkdir -p \${DEPLOY_PATH}/{releases,shared/backend,shared/storage}
cp /pfad/zu/.env \${DEPLOY_PATH}/shared/backend/.env   # Produktive .env hinterlegen
chown -R www-data:www-data \${DEPLOY_PATH}
```

### GitHub-Secrets anlegen

| Secret          | Bedeutung                                           |
| --------------- | --------------------------------------------------- |
| `DEPLOY_HOST`   | Server (Hostname oder IP)                           |
| `DEPLOY_USER`   | SSH-Benutzer, der auf `DEPLOY_PATH` schreiben darf  |
| `DEPLOY_KEY`    | Private SSH-Key (Base64 oder Klartext, je nach UI)  |
| `DEPLOY_PATH`   | Basisverzeichnis auf dem Server, z. B. `/var/www/lokato` |

### Workflow starten

* Automatisch bei Push auf `main` oder manuell via „Run workflow“.
* Bei erfolgreichem Lauf zeigt `current` immer auf den letzten Release; Rollback = alten Release erneut auf `current` verlinken.

Der Workflow setzt ausschließlich auf standardisierte Actions (Checkout, Setup PHP/Node, SCP/SSH) und hält damit die CI/CD-Kette minimal.
