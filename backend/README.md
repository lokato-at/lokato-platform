# 📦 Lokato Platform – Backend (Laravel)

Ein Backend-System zur **Erfassung, Anzeige und Auswertung von Kinderbewegungen** innerhalb eines Horts.
Das Backend basiert auf **Laravel 11/12** und stellt eine vollständige REST-API bereit.

---

## 🚀 Features

### 🔹 Hauptfunktionen

* **RFID-Scan Verarbeiten**
  `/api/v1/scan` nimmt Geräte-Events entgegen und speichert Bewegungen atomar.

* **Kinder-Standorte in Echtzeit**
  `/api/v1/children` liefert den aktuellen Raum eines jeden Kindes.

* **Raumbelegung + Kapazitäten**
  `/api/v1/rooms`, `/api/v1/rooms/{id}/occupancy`

* **Bewegungslog (Historie)**
  `/api/v1/movement-log` + Filter + Pagination

### 🔹 Admin-Funktionen (CRUD)

* `/api/v1/admin/children`
* `/api/v1/admin/rooms`
* `/api/v1/admin/devices`



### 🔹 Geräteverwaltung

* Jedes Gerät besitzt einen **device_key**, z.B. `raspberry_2`
* Geräte können unabhängig vom Standort getauscht werden
* `last_seen` zeigt an, ob Gerät aktiv ist

### 🔹 Logging

Eigener Log-Channel für Scanner:

```
storage/logs/scan.log
```

---

# 🔧 Requirements

* PHP **>= 8.2**
* Composer
* MySQL
* Node.js + npm
* OpenSSL & intl (für Laravel)
* Optional: Redis für Cache/Queue

---

# 🛠 Installation (Local Development)

## 1. Repository klonen

```bash
git clone https://github.com/dein-repo/lokato-platform-backend.git
cd lokato-platform-backend
```

## 2. Abhängigkeiten installieren

### PHP

```bash
composer install
```

### Node (Assets – optional)

```bash
npm install
```

## 3. `.env` anlegen

```bash
cp .env.example .env
```

## 4. App Key generieren

```bash
php artisan key:generate
```

---

# 🔐 Beispiel-ENV

```env
APP_NAME=Lokato
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8001

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lokato_db
DB_USERNAME=root
DB_PASSWORD=

FILESYSTEM_DISK=public
LOG_CHANNEL=stack
```

---

# 🗄 Datenbank vorbereiten

### Migrationen

```bash
php artisan migrate
```

### Speicher für Uploads verlinken

```bash
php artisan storage:link
```

Jetzt sind Dateien unter
`storage/app/public/...` → erreichbar über `/storage/...`

---

# 🧪 Feature Tests ausführen

```bash
php artisan test
```

Damit `RefreshDatabase` funktioniert, solltest du eine Test-DB haben:

In `phpunit.xml`:

```xml
<env name="DB_DATABASE" value="lokato_test"/>
```

---

# 📡 API-Endpoints

## 📍 Scan (Scanner-Schnittstelle)

### POST `/api/v1/scan`

Body:

```json
{
  "device_key": "raspberry_1",
  "tracker_uid": "TAG-0010",
  "event_time": "2025-11-17T14:30:00+01:00"
}
```

Antwort:

```json
{
  "status": "ok",
  "movement": { ... }
}
```

---

## 🧒 Kinder-API

* `GET /api/v1/children`
* `GET /api/v1/children/{id}`
* `GET /api/v1/children/{id}/movement-log`

---

## 🏠 Räume

* `GET /api/v1/rooms`
* `GET /api/v1/rooms/{id}/occupancy`

---

## 🔧 Admin-API (CRUD)

**Geschützt via Middleware (optional):**

```
/api/v1/admin/children
/api/v1/admin/rooms
/api/v1/admin/devices
```

Kinder-Foto Upload:

```
multipart/form-data
photo: file
```

---

# 📥 Logging

## Custom-Scanner-Log

Ein eigener Channel speichert alle Scan-Events (Erfolg + Fehler):

`config/logging.php`:

```php
'scan' => [
    'driver' => 'single',
    'path' => storage_path('logs/scan.log'),
    'level' => 'info',
],
```

Beispiele:

```php
Log::channel('scan')->warning('Unknown device_key', [...]);
Log::channel('scan')->info('Scan processed', [...]);
```

---

# 🚀 Deployment (Production)

1. Repo pullen
2. `.env` für Prod anpassen
   `APP_DEBUG=false`
3. Composer install *ohne dev*:

   ```bash
   composer install --optimize-autoloader --no-dev
   ```
4. Optimierungen:

   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
5. Migrationen:

   ```bash
   php artisan migrate --force
   ```
6. Storage-Link (falls noch nicht vorhanden):

   ```bash
   php artisan storage:link
   ```

### Rechte

PHP muss Schreibrechte auf folgende Ordner haben:

```
storage/
bootstrap/cache/
```



---

# 🧯 Troubleshooting

### ❗ Geräte werden nicht erkannt

* device_key stimmt nicht?
* Gerät nicht in DB?
* Raum nicht zugewiesen?

### ❗ Kinder haben keinen Raum

Prüfen:

* child_locations-Eintrag vorhanden?
* letzter Scan ist älter als aktueller?

### ❗ Uploads funktionieren nicht

* `php artisan storage:link`
* Schreibrechte auf `storage/app/public`

---
