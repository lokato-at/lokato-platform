# lokato-platform
Frontend, Backend, Database, REST-APIs

Tristan
Nikolai
Selina
Edina

# 🚀 Lokato Platform – Full Stack Setup

Backend (Laravel) • Frontend (Vue/Vite) • MySQL (Docker)

Dieses Projekt bildet das digitale Tracking-System *Lokato* (RFID/NFC-gestütztes Raumtracking für Kinder).
Es besteht aus:

* **backend/** – Laravel 11/12 REST API
* **frontend/** – Vue 3 + Vite
* **docker/** – persistente MySQL-Datenbank

---

# 📦 Voraussetzungen

Installiere vorab:

* **Docker Desktop** + **WSL2** (Windows)
* **PHP ≥ 8.2**
* **Composer**
* **Node.js (LTS Version)**
* **Git**

### Genutzte Ports

* MySQL → **3306**
* Backend → **8001**
* Frontend → **5173**

---

# 🛠 1. Initiales Setup (nur beim ersten Mal)

## 1.1 Repository klonen

```bash
git clone <REPO_URL> lokato-platform
cd lokato-platform
```

---

## 1.2 MySQL in Docker starten

```bash
cd docker
docker compose up -d
cd ..
```

### Check ob MySQL läuft:

```bash
docker ps
```

Es muss ein Container mit Port **3306** sichtbar sein.

---

# 🕋 1.3 Backend (Laravel) installieren

```bash
cd backend
composer install
cp .env.example .env
```

### `.env` konfigurieren:

```
APP_URL=http://127.0.0.1:8001

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lokato_db
DB_USERNAME=admin
DB_PASSWORD=admin
```

### App-Key erzeugen:

```bash
php artisan key:generate
```

### Migrationen + Seed:

```bash
php artisan migrate --seed
```

---

## Backend starten

Standard:

```bash
php artisan serve --host=127.0.0.1 --port=8001
```

### ⚠️ WICHTIG

Falls dieser Fehler kommt:

```
Failed to listen on 127.0.0.1:8001 (reason: ?)
```

Dann **diesen alternativen Startbefehl** verwenden:

```bash
php -d variables_order=GPCS artisan serve --host=127.0.0.1 --port=8001
```

➡ Ursache: Manche PHP-Installationen haben falsche `variables_order`-Settings.

---

### Backend Health Check

Öffne:

```
http://127.0.0.1:8001/api/ping
```

oder:

```
http://127.0.0.1:8001/api/health
```

---

# 🎨 1.4 Frontend installieren

```bash
cd ../frontend
npm install
npm run dev
```

Frontend:

```
http://localhost:5173
```

---

# 🧪 1.5 Tests

### Backend-Tests:

```bash
cd backend
php artisan test
```

---

# 🌡 1.6 Installation überprüfen

| Bereich            | Check                              |
| ------------------ | ---------------------------------- |
| Docker läuft       | `docker ps` zeigt MySQL            |
| Backend erreichbar | `http://127.0.0.1:8001/api/ping`   |
| DB ok              | `php artisan migrate` funktioniert |
| Frontend startet   | `npm run dev`                      |
| API ↔ Frontend     | Netzwerkaufrufe funktionieren      |

---

# 🔁 2. Projekt starten nach PC-Neustart

Ab jetzt brauchst du nur die **Start-Befehle**:

## 2.1 MySQL hochfahren

```bash
cd docker
docker compose up -d
cd ..
```

## 2.2 Backend starten

Standard:

```bash
cd backend
php artisan serve --host=127.0.0.1 --port=8001
```

Falls Fehler → Alternative:

```bash
php -d variables_order=GPCS artisan serve --host=127.0.0.1 --port=8001
```

## 2.3 Frontend starten

```bash
cd frontend
npm run dev
```

---

# 📚 Nützliche Laravel-Befehle

- **php artisan optimize:clear**  
  Löscht alle Laravel-Caches (Config, Route, View, Events).

- **php artisan route:list**  
  Listet alle registrierten Routen der Anwendung auf.

- **php artisan migrate:status**  
  Zeigt den Status aller Datenbankmigrationen (ausgeführt / nicht ausgeführt).

- **php artisan storage:link**  
  Erstellt einen Symlink, um `storage/app/public` über `public/storage` zugänglich zu machen.

- **php artisan migrate:reset**  
  Macht **alle Migrationen rückgängig** und setzt die Datenbank auf den Zustand vor den Migrationen zurück.


---

# 🧰 Troubleshooting

### ❗ Backend startet nicht (Port 8001 Fehler)

Wenn du dies siehst:

```
Failed to listen on 127.0.0.1:8001 (reason: ?)
```

dann:

```bash
php -d variables_order=GPCS artisan serve --host=127.0.0.1 --port=8001
```

### ❗ MySQL-Container startet nicht

* Port 3306 belegt?
* Prüfen:

  ```bash
  netstat -ano | findstr 3306
  ```

### ❗ Laravel erreicht MySQL nicht

In `backend/`:

```bash
php artisan tinker
DB::connection()->getPdo();
```

Wenn Fehler → `.env` prüfen.

### ❗ Vite-Port 5173 belegt

```bash
npm run dev -- --port=5174
```

### ❗ vendor fehlt

Immer im Ordner `backend/` ausführen:

```bash
cd backend
composer install
```


---

# 🏭 Produktions-Deployment

Für ein schlankes Produktiv-Setup (Docker-DB, Laravel-API, Vite-Frontend) gibt es eine Schritt-für-Schritt-Anleitung in `docs/DEPLOYMENT.md`.
