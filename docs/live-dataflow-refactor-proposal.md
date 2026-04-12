# Live-Datenfluss Refactor-Vorschlag (Repo-spezifisch)

Stand: 2026-04-12

## 1) Ist-Analyse aus dem aktuellen Code

### Bestehender Datenfluss
1. Hardware publiziert MQTT auf Topic `/api/v1/scan` (laut README & Laravel Command Defaults).
2. Laravel-CLI-Worker `php artisan mqtt:subscribe` konsumiert MQTT und ruft `ScanIngestService::ingestScan(...)` auf.
3. Persistenz erfolgt in `movement_log`, `child_locations`, `devices.last_seen`.
4. Frontend lädt Initialdaten via REST (`/api/v1/rooms?include_children=true`, `/api/v1/movement-log`).
5. Frontend öffnet anschließend SSE gegen Laravel `/api/stream/dashboard`.
6. Laravel-SSE pollt jede Sekunde die DB, erkennt neue `movement_log`/`alerts`, emittiert SSE-Events.

### Auffälligkeiten / Engpässe
- **Doppelte Echtzeit-Verantwortung in Laravel**: Ingestion + SSE-Streaming + Polling-Loop.
- **Polling-basierte SSE**: Für jeden verbundenen Client läuft DB-Polling (1s Loop), skaliert bei mehreren Clients schlecht.
- **SSE-Lifecycle in App-Server**: Lange HTTP-Verbindungen im Laravel-Prozess sind unnötig teuer für reine CRUD/API-Workloads.
- **MQTT Listener als manueller Prozess**: Startskripte starten ihn separat; robustere Trennung als eigener Service wäre sinnvoll.
- **Event-Normalisierung fehlt für Realtime-Pfad**: Frontend erhält aktuell SSE-Eventformat, aber kein dediziertes Realtime-Schema mit Versionierung.

## 2) Zielarchitektur (empfohlen)

## Überblick
- **Mosquitto** bleibt Broker für Hardware-Events.
- **Neuer Realtime-Ingest-Service (Node.js/TypeScript)** übernimmt:
  - MQTT Subscribe
  - Payload-Validierung + Idempotenz
  - Persistenz in MySQL (direkt oder via SQL-Repo-Layer)
  - Realtime-Auslieferung via WebSocket (Socket.IO oder WS)
- **Laravel** bleibt für:
  - Initialdaten / Snapshot-Endpunkte
  - Admin/CRUD
  - Historie/Reporting
  - optionale fachliche Endpunkte
- **Frontend**:
  - Initialsnapshot via REST von Laravel
  - Live-Delta via WebSocket vom Realtime-Service

## Datenfluss
1. Device -> MQTT Topic `lokato/scan/v1` (oder parallel zunächst weiterhin `/api/v1/scan`).
2. Realtime-Service konsumiert MQTT, validiert Payload, dedupliziert über Event-Key.
3. Realtime-Service schreibt transaktional in `movement_log` + `child_locations` + `devices.last_seen` (äquivalent zu `ScanIngestService`).
4. Realtime-Service publiziert nach erfolgreichem Commit WebSocket-Events (`child.moved`, `room.occupancy.updated`, optional `device.last_seen`).
5. Frontend wendet Deltas direkt an.

## Warum WebSocket statt SSE im Zielbild
- MQTT-naher Service kann bidirektionale/ack-basierte Patterns später einfacher ergänzen.
- Weniger Overhead für viele gleichzeitige Clients als pro-Client Polling in Laravel.
- Saubere Entkopplung: Laravel liefert keine Long-Lived Live-Streams mehr.

> Falls ihr strikt bei SSE bleiben wollt, sollte SSE dann trotzdem im **Realtime-Service** liegen (nicht in Laravel), idealerweise event-getrieben statt DB-Polling.

## 3) Verantwortlichkeiten (klar getrennt)

### Mosquitto
- Nur Broker-Rolle.
- ACL/Authentifizierung und Topic-Segregation (ingest vs. broadcast/system topics).

### Realtime-Service (neu/angepasster Container)
- MQTT Consumer + Parser + Validator.
- Idempotenz-/Duplikatfilter.
- Persistenz-Transaktionen.
- Realtime-WebSocket Hub.
- Kurze technische Health/metrics Endpoints (`/health`, `/metrics`).

### Laravel
- REST: `/rooms`, `/children`, `/movement-log`, Admin CRUD.
- Liefert Initialdaten „letzter Status je Gerät/Kind/Raum“ aus MySQL.
- Kein Live-Streaming der Gerätedaten mehr.

## 4) Konkrete Änderungen im bestehenden Repo

### Backend Laravel
1. **SSE aus Routen entfernen**: `backend/routes/api.php` Prefix `/stream` entfernen.
2. **SSE Controller entfernen**: `backend/app/Http/Controllers/Api/SseStreamController.php` löschen.
3. **SSE Konfiguration bereinigen**:
   - `SSE_MAX_CONNECTION_SECONDS` aus `.env.example` entfernen.
   - `config/app.php` Eintrag `sse_max_connection_seconds` entfernen.
   - Logging Channel `sse` in `config/logging.php` entfernen (falls nur hierfür genutzt).
4. **Ingest-Logik extrahieren als Referenzvertrag**:
   - Fachlogik aus `ScanIngestService` 1:1 im Realtime-Service spiegeln.
   - Optional: `POST /api/v1/scan` mittelfristig als Fallback/Debug belassen oder deaktivieren.
5. **Neuer Initial-Endpunkt (optional, empfohlen)**:
   - `/api/v1/dashboard/initial` mit rooms+children+latest movements in einem Payload, um Frontend-Startup zu vereinfachen.

### Frontend Vue/TS
1. `dashboardDataStore.ts`:
   - `connectSSE()/disconnectSSE()` ersetzen durch WS-Client.
   - Event-Typen behalten (`child.moved`, `room.occupancy.updated`), damit UI-Handling nahezu unverändert bleibt.
2. `DashboardView.vue`:
   - Connection-Status-Label auf „LiveSocket verbunden“. 
3. `utils/api.ts`:
   - Ergänzung `buildRealtimeUrl()` (z. B. aus `VITE_REALTIME_BASE_URL`).
4. `.env.example`:
   - Neue Variable `VITE_REALTIME_BASE_URL` ergänzen.

### Docker / Infra
1. `docker/docker-compose.yml`:
   - Service `realtime` hinzufügen (Node/TS).
   - `realtime` hängt von `db` + `mqtt` ab.
   - Exponierter Port für WS (z. B. 8081 intern, ggf. extern via Reverse Proxy).
2. Reverse Proxy (falls in Haupt-Deployment vorhanden):
   - `/realtime` -> Realtime-Service (WS Upgrade Header korrekt).
   - `/api` -> Laravel.
3. Startskripte (`start-prod-raspi.sh`, `start-dev.ps1`):
   - Laravel `mqtt:subscribe` Start entfernen.
   - Realtime-Service Start ergänzen.

## 5) Datenmodell / Persistenz / Idempotenz (wichtig)

### Minimal-invasiv (empfohlen)
- Bestehende Tabellen weiterverwenden (`movement_log`, `child_locations`, `devices`).
- Zusätzliche Tabelle für Idempotenz:
  - `ingested_events` mit `event_hash` (unique), `device_key`, `tracker_uid`, `event_time`, `ingested_at`.
- Hash-Bildung: `sha256(device_key + '|' + tracker_uid + '|' + normalized_event_time)`.

### Warum?
- MQTT QoS 0/1 kann Duplikate liefern.
- Ohne Dedupe entstehen doppelte Bewegungslogs und unnötige Live-Updates.

## 6) Payload-Format-Einschätzung

Aktuell:
```json
{"device_key":"RaspberryChild02","tracker_uid":"0X000017570D02640950B9462C","event_time":"2026-01-26T12:00:00+00:00"}
```

### Kurzfristig (kompatibel)
- Beibehalten, aber serverseitig strikt validieren:
  - `device_key`: known + Pattern
  - `tracker_uid`: known + Pattern
  - `event_time`: ISO-8601, UTC normalisieren

### Mittelfristig (empfohlen)
- Um technische Metadaten erweitern:
```json
{
  "schema": "lokato.scan.v1",
  "message_id": "uuid-v7",
  "device_key": "RaspberryChild02",
  "tracker_uid": "0X000017570D02640950B9462C",
  "event_time": "2026-01-26T12:00:00Z",
  "published_at": "2026-01-26T12:00:01Z"
}
```
- `message_id` vereinfacht Idempotenz massiv.
- `schema` erlaubt versionierte Evolution.

## 7) Risiken & Gegenmaßnahmen

1. **Divergenz zur Laravel-Ingestlogik**
   - Gegenmaßnahme: Fachregeln dokumentieren + Contract-Tests gegen bekannte Fixtures.
2. **Out-of-order Events**
   - Gegenmaßnahme: wie bisher `child_locations` nur aktualisieren, wenn `occurred_at >= updated_at`.
3. **Teilweise Persistenzfehler**
   - Gegenmaßnahme: DB-Transaktion pro Event.
4. **WS-Reconnect im Frontend**
   - Gegenmaßnahme: Exponential Backoff + Rehydrate via REST nach reconnect.

## 8) Migrationsplan (inkrementell, mit wenig Risiko)

### Phase 0 – Vorbereitung
- Realtime-Service skeleton im bestehenden Repo anlegen (eigener Container).
- DB-Zugriff + MQTT + WS Grundgerüst.

### Phase 1 – Paralleler Betrieb
- Realtime-Service konsumiert MQTT und schreibt in DB **zunächst read-only shadow mode** optional.
- Vergleich mit Laravel-Ingest-Ergebnissen (Logs/Stats).

### Phase 2 – Frontend umschalten
- Dashboard live von SSE -> WS (Feature Flag möglich).
- Initialdaten bleiben Laravel-REST.

### Phase 3 – Ingest umschalten
- MQTT nur noch durch Realtime-Service verarbeiten.
- Laravel `mqtt:subscribe` aus Betriebsdoku und Startskripten entfernen.

### Phase 4 – Cleanup
- SSE-Routen/Controller/Konfig vollständig entfernen.
- Dokumentation und Betriebshandbuch aktualisieren.

## 9) Offene Punkte / Annahmen aus Repo-Sicht

1. Im aktuellen Repo ist **kein bestehender Realtime-Container** sichtbar (nur db/phpmyadmin/mqtt in Compose). Falls es einen externen Container im übergeordneten Repo gibt, sollte dieser bevorzugt erweitert werden.
2. Es gibt derzeit **keine Auth auf Live-Endpunkt**; das bleibt umsetzbar, sollte aber wenigstens netzwerkseitig eingeschränkt werden.
3. `POST /api/v1/scan` existiert parallel zum MQTT-Ingest — Entscheidung nötig, ob dieser Pfad als manueller Fallback bestehen bleibt.
4. Topic-Strategie ist aktuell API-artig (`/api/v1/scan`); fachlich sauberer wäre MQTT-domänenspezifisch (`lokato/scan/v1`).

## 10) Implementierungsreihenfolge (praktisch)

1. Realtime-Service im `docker/` Setup ergänzen.
2. WS-Client im Frontend einführen, Event-Handler aus SSE wiederverwenden.
3. Realtime-Service schreibt produktiv in DB + sendet Live-Events.
4. Laravel SSE-Endpunkte entfernen.
5. Start-/Deploy-Skripte und READMEs aktualisieren.
