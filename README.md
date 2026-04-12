# Lokato Platform - Backend, Frontent, Database

## Überblick

Dieses Repository enthält den Code für Backend, Frontend und Datenbank-Anbindung.
RFID-Scans werden über MQTT an das Backend gesendet, dort von einem Realtime-Service verarbeitet und in Echtzeit per WebSocket an das Browser-Frontend übertragen.

Es existiert ein übergeordnetes Main-Repository, das weitere Projektbereiche (z.B. Hardware, Infrastruktur, Prototype Setup) bündelt. [Lokato-main](https://github.com/lokato-at/lokato-main.git)

Diese README beschreibt **das lokale Entwicklungs-Setup** für Entwickler:innen.

---

## Architektur (Kurzfassung)

* **Frontend**

  * Vue.js 3 + TypeScript + Pinia
  * Läuft im Browser
  * Kommunikation:

    * REST (CRUD)
    * WebSocket (Live-Updates via Realtime-Service)

* **Backend**

  * Laravel (PHP)
  * REST API für Initialdaten, CRUD und Admin

* **Realtime Service**

  * Node.js Service
  * MQTT Subscriber + Persistenz + WebSocket Live-Events

* **Docker**

  * MySQL Datenbank
  * Mosquitto MQTT Broker

* **Hardware (konzeptionell)**

  * RFID Reader → ESP32 → MQTT Publish

---

## Technologie-Stack

* Frontend: Vue 3, TypeScript, Pinia, Vite
* Backend: Laravel (REST)
* Realtime: Node.js (MQTT Ingest + WebSocket)
* Messaging: MQTT (Mosquitto)
* Datenbank: MySQL (Docker)

---

## Voraussetzungen

Bitte stelle sicher, dass folgende Tools installiert sind:

* Docker & Docker Compose
* PHP (passend zur Laravel-Version)
* Composer
* Node.js + npm

---

## Ports & URLs (lokal)

| Dienst       | URL / Port                                                   |
| ------------ | ------------------------------------------------------------ |
| Frontend     | [http://localhost:5173](http://localhost:5173)               |
| Backend API  | [http://localhost:8001](http://localhost:8001)               |
| API Base URL | [http://localhost:8001/api/v1](http://localhost:8001/api/v1) |
| MySQL        | localhost:3306 (Docker)                                      |
| phpmyadmin   | [http://localhost:8090](http://localhost:8090/) (Docker)     |
| MQTT Broker  | 1883                                                         |

---

## Lokales Dev-Setup

### 1. Docker Container starten

```bash
cd docker
docker compose up -d
```

Dadurch werden gestartet:

* MySQL
* Mosquitto MQTT Broker

---

### 2. Backend einrichten & starten

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8001
```

Backend läuft anschließend unter:

```
http://localhost:8001
```

---

### 3. Frontend einrichten & starten

```bash
cd frontend
npm install
npm run dev
```

Frontend läuft unter:

```
http://localhost:5173
```

---

## Realtime-Service

Der Realtime-Service läuft als Docker-Container (`realtime`) und übernimmt MQTT-Ingest + Persistenz + WebSocket-Liveevents.

## WebSocket – Live Updates

Das Frontend erhält Live-Daten über den Realtime-Service (WebSocket):

```
ws://localhost:8081/ws
```

---

## Testen ohne Hardware 

Um das System ohne ESP32/RFID-Hardware zu testen, können MQTT-Nachrichten manuell gesendet werden.

### MQTT Subscribe (Debug)

```bash
docker exec -it lokato-mosquitto mosquitto_sub -v -t "/api/v1/scan"
```

### MQTT Publish (Scan simulieren)

```bash
docker exec -it lokato-mosquitto mosquitto_pub \
  -t "/api/v1/scan" \
  -m '{"device_key":"RaspberryChild02","tracker_uid":"0X000017570D02640950B9462C","event_time":"2026-01-26T12:00:00+00:00"}'
```

Erwartetes Verhalten:

* Realtime-Service verarbeitet das Event
* WebSocket sendet Live-Update
* Frontend aktualisiert sich automatisch

---

## Logging & Debugging

Im Ordner `backend/storage/logs`:

* `scan.log`

  * Alle verarbeiteten Scan-Events (MQTT)

* `laravel.log`

  * Allgemeine Backend-Logs

Diese Logs sind die **erste Anlaufstelle bei Problemen**.

---

## Troubleshooting

### Keine Live-Updates im Frontend

* Läuft der Realtime-Container?
* Ist `ws://localhost:8081/ws` erreichbar?

### MQTT Events kommen nicht an

* Läuft der Mosquitto Container?
* Stimmt das Topic `/api/v1/scan`?
* Siehe `scan.log`

---

## Hinweise

* Diese README beschreibt **das Dev-Setup**
* Deployment & Raspberry-Pi-Konfiguration sind separat dokumentiert
* Hardware ist für lokale Entwicklung **nicht erforderlich**

---


## Startskripte

### Windows Entwicklung

Das Script `start-dev.ps1` prüft/installiert bei Bedarf per `winget`:

* Docker Desktop
* PHP
* Composer
* Node.js / npm

Danach stellt es sicher, dass Docker läuft, startet die Container aus `docker/docker-compose.yml`, legt fehlende `.env`-Dateien aus den Example-Dateien an, führt `composer install` / `npm install` bei Bedarf aus, migriert Laravel und startet:

* Backend (`php artisan serve`)
* Frontend (`npm run dev`)

Beispiel:

```powershell
./start-dev.ps1
```

Optional:

```powershell
./start-dev.ps1 -BackendPort 8001 -FrontendPort 5173
./start-dev.ps1 -SkipInstalls
```

### Raspberry Pi OS / Linux Produktion

Das Script `start-prod-raspi.sh` ist für einen einfachen produktionsnahen Raspberry-Pi-Betrieb gedacht. Es installiert – sofern `INSTALL_DEPS=1` gesetzt ist – die benötigten Systempakete via `apt`, startet Docker, fährt die Infrastrukturcontainer hoch, installiert Backend/Frontend-Abhängigkeiten, baut das Frontend, cached Laravel und startet anschließend im Hintergrund:

* Backend API
* Frontend Preview
* Realtime Service (Docker Container)

Beispiel:

```bash
chmod +x start-prod-raspi.sh stop-prod-raspi.sh
./start-prod-raspi.sh
```

Stoppen:

```bash
./stop-prod-raspi.sh
```

Die Hintergrundprozesse schreiben Logs nach `./logs` und PID-Dateien nach `./.run`.

## .env / Docker Compose

### `backend/.env`

Relevante Variablen:

```env
API_SLOW_REQUEST_MS=400
MQTT_HOST=127.0.0.1
MQTT_PORT=1883
```

### `frontend/.env`

```env
VITE_API_BASE_URL=http://localhost:8001/api/v1
VITE_REALTIME_BASE_URL=http://localhost:8081
```

### `docker/docker-compose.yml`

Der Compose-Stack enthält jetzt zusätzlich den Service `realtime` (MQTT-Ingest + Persistenz + WebSocket), neben `db`, `phpmyadmin` und `mqtt`.
