# Lokato Platform - Backend, Frontent, Database

## Überblick

Dieses Repository enthält den Code für Backend, Frontend und Datenbank-Anbindung.
RFID-Scans werden über MQTT an das Backend gesendet, dort verarbeitet und in Echtzeit per Server-Sent Events (SSE) an das Browser-Frontend übertragen.

Es existiert ein übergeordnetes Main-Repository, das weitere Projektbereiche (z.B. Hardware, Infrastruktur, Prototype Setup) bündelt. [Lokato-main](https://github.com/lokato-at/lokato-main.git)

Diese README beschreibt **das lokale Entwicklungs-Setup** für Entwickler:innen.

---

## Architektur (Kurzfassung)

* **Frontend**

  * Vue.js 3 + TypeScript + Pinia
  * Läuft im Browser
  * Kommunikation:

    * REST (CRUD)
    * SSE (Live-Updates)

* **Backend**

  * Laravel (PHP)
  * REST API
  * SSE Endpunkte für Live-Daten
  * MQTT Subscriber zur Verarbeitung von Scan-Events

* **Docker**

  * MySQL Datenbank
  * Mosquitto MQTT Broker

* **Hardware (konzeptionell)**

  * RFID Reader → ESP32 → MQTT Publish

---

## Technologie-Stack

* Frontend: Vue 3, TypeScript, Pinia, Vite
* Backend: Laravel (REST, SSE)
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

Im Projektverzeichnis:

```bash
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

## MQTT Subscriber

Das Backend verarbeitet RFID-Scans **nicht automatisch**.
Der MQTT Subscriber muss **manuell** in einem neuen Terminal gestartet werden.

### Start des Subscribers

```bash
php artisan mqtt:subscribe
```

### Optionen

* `--once`

  * Verarbeitet genau **einen** Scan und beendet sich danach

* `--debug`

  * Zusätzliche Debug-Ausgaben im Terminal

Beispiel:

```bash
php artisan mqtt:subscribe --debug --once
```

### Typischer Dev-Workflow

* Terminal 1: Docker läuft
* Terminal 2: Backend (`php artisan serve`)
* Terminal 3: MQTT Subscriber
* Terminal 4: Frontend

---

## SSE – Live Updates

Das Frontend erhält Live-Daten über **Server-Sent Events (SSE)**.

### SSE Endpunkte

* Dashboard:

  ```
  GET /api/stream/dashboard
  ```

* Raum-spezifisch:

  ```
  GET /api/stream/room/{room}
  ```

Beispiel:

```
http://localhost:8001/api/stream/dashboard
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

* Backend verarbeitet das Event (Subscriber muss laufen)
* SSE sendet Live-Update
* Frontend aktualisiert sich automatisch

---

## Logging & Debugging

Im Ordner `backend/storage/logs`:

* `scan.log`

  * Alle verarbeiteten Scan-Events (MQTT)

* `sse.log`

  * SSE Verbindungen & Events

* `laravel.log`

  * Allgemeine Backend-Logs

Diese Logs sind die **erste Anlaufstelle bei Problemen**.

---

## Troubleshooting

### Keine Live-Updates im Frontend

* Läuft der MQTT Subscriber?
* Ist das SSE-Endpoint erreichbar?
* Siehe `sse.log`

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


