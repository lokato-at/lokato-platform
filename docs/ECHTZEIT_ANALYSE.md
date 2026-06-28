# Echtzeit-System — Statische Analyse

Stand: 2026-06-27. Diese Analyse beschreibt, **wie** Lokato Live-Daten zwischen
ESP32-Scannern, Browser-Clients und Backend-Prozessen synchronisiert, **welche
Komponenten** daran beteiligt sind, **welche Caches** den Hot-Path entlasten,
und **wo die Performance-Limits** liegen.

> **TL;DR**: Lokato benutzt klassisches **Server-Sent Events (SSE)** zwischen
> Browser und Laravel. Es gibt kein Pub/Sub, kein WebSocket-Server, kein Redis.
> Stattdessen pollt jeder verbundene Client unabhängig die DB alle 500 ms,
> aber ein **Cache-Gate** (`sse:last_change_at`) bringt die DB-Last im Idle auf
> nahezu 0 — Polls werden übersprungen solange sich nichts geändert hat.
> MQTT (Mosquitto) ist nur die Brücke vom Scanner zum Backend, nicht zum
> Browser.

---

## 1. Komponenten-Inventur

```
   ┌──────────────────┐                ┌──────────────────────────┐
   │  ESP32 + UHF-    │   MQTT pub     │  Mosquitto 2 (TCP 1883)  │
   │  RFID-Scanner    │ ─────────────▶ │  Topic /api/v1/scan      │
   └──────────────────┘                └────────────┬─────────────┘
                                                    │ MQTT subscribe
                                                    ▼
   ┌──────────────────────────────────────────────────────────────┐
   │  lokato-mqtt (systemd unit, Laravel CLI)                     │
   │  MqttSubscribeCommand → ScanIngestService::ingestScan()      │
   │                       → SseChangeSignal::bump()              │
   └──────────────────────────────────────────────────────────────┘
                                                    │
                                                    ▼
   ┌──────────────────────────────────────────────────────────────┐
   │  MariaDB                                                      │
   │  movement_log (append-only) · child_locations (state)         │
   │  rooms · children · devices · alerts · cache (DB-driver)     │
   └──────────────────────────────────────────────────────────────┘
                       ▲                            ▲
                       │ Eloquent/SQL               │ Eloquent
                       │                            │
   ┌───────────────────┴──────┐    ┌─────────────────┴──────────┐
   │  nginx :80               │    │  SseStreamController        │
   │  /api/v1/* → php-fpm REST│    │  GET /api/stream            │
   │  /        → Vue SPA      │    │  text/event-stream          │
   └─────────┬────────────────┘    └─────────────────────┬──────┘
             │                                            │
             ▼ REST                                       ▼ SSE
   ┌────────────────────────────────────────────────────────────┐
   │  Browser-Clients (Vue 3 SPA, Pinia stores)                  │
   │  · Dashboard       (dashboardDataStore)                     │
   │  · Tablet pro Raum (roomTabletStore)                        │
   │  · Admin-Views     (adminDataStore)                         │
   │  · LoginView       (authStore)                              │
   └────────────────────────────────────────────────────────────┘
```

### Beteiligte Prozesse auf dem Pi

| Prozess | Rolle | Lebensdauer |
|---|---|---|
| `nginx` | Reverse Proxy, statische Assets, FastCGI-Bridge | Daemon |
| `php-fpm` | PHP-Workers für REST + SSE-Streams | `pm=dynamic`, max 12 worker |
| `mariadbd` | Datenbank | Daemon |
| `mosquitto` | MQTT-Broker | Daemon |
| `lokato-mqtt` | systemd-Service, Laravel `mqtt:subscribe` CLI | Long-running, restart=always |
| `cron` (system) | triggert `php artisan schedule:run` jede Minute | jeden ~60 s neuer Lauf |

---

## 2. Datenflüsse

### 2.1 Scan-Pfad (Schreibseite)

Drei mögliche Eintrittspunkte, alle landen im selben `ScanIngestService`:

| Pfad | Eintritt | Auth | Bumpt SSE? |
|---|---|---|---|
| Produktion (Hardware) | `ESP32 → MQTT pub /api/v1/scan → lokato-mqtt` | – | ✅ ja (Subscriber) |
| REST-Fallback | `POST /api/v1/scan` (für Test/Backup) | nein | ✅ ja (Controller) |
| **`tinker`-Direct-Call** | `php artisan tinker` → `ingestScan()` | – | ❌ **nein** (bewusst, siehe §6) |

Innerhalb `ScanIngestService::ingestScan()`:

1. `DB::transaction(...)` (atomisch)
2. `lockForUpdate()` auf `Child` und `ChildLocation` (verhindert Race bei zwei gleichzeitigen Scans desselben Kindes)
3. `MovementLog::create(...)` — append-only, neuer Auto-Inc-ID dient als SSE-Cursor
4. `ChildLocation` aktualisiert (oder erstellt) — idempotent via `occurred_at`-Vergleich
5. Wenn `child.is_active === false` → wird auf `true` gesetzt (deshalb sind Kinder „erst beim Scan aktiv")
6. `Device::last_seen` aktualisiert (lightweight Update)
7. Strukturiertes Logging via `AppLogger::event('scan', 'scan_processed', ...)`

Nach erfolgreicher Transaktion ruft **der Aufrufer** (`MqttSubscribeCommand` oder `DeviceEventController`) `SseChangeSignal::bump()` auf — der Service selbst bleibt davon unangetastet (siehe §6 zur Begründung).

### 2.2 Push-Pfad (Leseseite)

Jeder Browser-Tab öffnet **eine SSE-Verbindung** zu `GET /api/stream`. Der Endpoint hat zwei Modi:

| Query | Modus | Genutzt von |
|---|---|---|
| `/api/stream` | Dashboard — alle Räume | `dashboardDataStore`, `adminDataStore` |
| `/api/stream?room=X&initial=1` | Tablet — gescoped auf Raum X + initialer Snapshot | `roomTabletStore` |

Der Stream sendet folgende Event-Typen:

| Event | Wann | Payload (Auszug) |
|---|---|---|
| `stream.ready` | Beim Connect | `{ scope, connected_at }` |
| `child.moved` | Bei jedem neuen `MovementLog`-Eintrag | `{ id, child_id, child:{id,name}, from_room:{id,name}, to_room:{id,name}, source, occurred_at }` |
| `room.occupancy.updated` | Bei Änderung der `child_locations` eines Raums oder Capacity-Change | `{ room_id, room_name, capacity, tolerance, current_count, children:[…], status:{ over_capacity, within_tolerance } }` |
| `room.status.updated` | Bei `RoomAdminController::update`/`store`/`destroy` (is_active / name / capacity / …) | `{ id, name, area, capacity, tolerance, is_active }` |
| `room.alert.raised` | Bei neuem `alerts`-Eintrag | `{ id, room_id, level, message, created_at, resolved_at }` |
| `stream.draining` | Nach 60 s Connection-Alter | `{ reason, reconnect: true }` |

Jedes Event hat einen `id`-Header im Format `movement:N;alert:M`. Bei Reconnect wird der Cursor als Query-Param `?last_event_id=…` zurückgegeben (browser sendet `Last-Event-ID`-Header nur beim auto-retry — manuelle EventSource-Konstruktion umgeht das).

### 2.3 REST-Pfad (Auth + Admin)

Auth-protected (`auth:sanctum`):
- `POST /auth/login` (throttle:10,1) · `POST /auth/logout` · `GET /auth/me`
- `POST /v1/children/{id}/checkout` (write, deshalb auth)
- `/v1/admin/{children,rooms,devices,summary}` — alle CRUD

Public (LAN-trust-boundary):
- `GET /v1/children` · `GET /v1/rooms` · `GET /v1/rooms/{id}/occupancy` · `GET /v1/movement-log` — read-only data für Tablets
- `POST /v1/scan` (throttle:120,1) — ESP32 ohne Auth-Schema
- `GET /api/stream` — SSE (EventSource sendet keine custom Header → kein Bearer möglich)

---

## 3. SSE-Mechanik im Detail

### 3.1 Was tut der `SseStreamController` pro Verbindung?

```
runStream():
  ┌─ Setup: cursor (lastMovementId, lastAlertId, lastRoomChangeAt) holen
  ├─ retry: 5000ms ausgeben
  ├─ stream.ready event senden
  ├─ Wenn initial=1 + room-scope: initial room.occupancy.updated senden
  │
  └─ Endlos-Loop (max 60 s):
       1. currentChange = Cache::get('sse:last_change_at')
       2. shouldQueryDb = forceFirst OR Cache nicht aktiv OR currentChange > lastSeen
       3. Wenn shouldQueryDb:
            - Poll movement_log WHERE id > lastMovementId       (limit 100)
                → emit child.moved + sammle changed_room_ids
            - OccupancySnapshotBuilder.forRoomIds(changed_room_ids, true)
                → emit room.occupancy.updated für jeden geänderten Raum
            - Poll alerts WHERE id > lastAlertId                (limit 100)
                → emit room.alert.raised
            - Poll rooms WHERE updated_at > lastRoomChangeAt    (limit 50)
                → emit room.status.updated
       4. Heartbeat alle 15 s (": heartbeat …\n\n")
       5. flush() → echo geht raus
       6. Wenn 60 s erreicht: emit stream.draining → break
       7. usleep(500_000) — 500 ms Tick
```

### 3.2 Cache-Gate (warum die DB im Idle ruhig bleibt)

Der teure Teil ist Punkt 3 (DB-Polls). Die werden **nur ausgeführt wenn der
Cache-Wert sich verändert hat**. Zwei getrennte Signale:

- `bump()` setzt `sse:last_change_at` — der Loop pollt MovementLog/Alert/Room.
- `bumpChildren()` setzt zusätzlich `sse:last_children_change_at` — triggert einen
  Full-Refresh aller Occupancy-Snapshots (für Aenderungen ohne MovementLog).

Bumper-Übersicht:

| Bumper | Methode | Wann |
|---|---|---|
| `MqttSubscribeCommand` | `bump()` | Nach erfolgreichem `ingestScan()` |
| `DeviceEventController::store` | `bump()` | Nach erfolgreichem `ingestScan()` |
| `ChildrenController::checkout` | `bump()` | Nach erfolgreichem Checkout |
| `ChildAdminController::update` | `bump()` | Update (is_active-Toggle schreibt MovementLog) |
| `ChildAdminController::store`/`destroy`/`uploadPhoto`/`deletePhoto` | `bumpChildren()` | Metadaten-Aenderung ohne MovementLog |
| `RoomAdminController::store` | `bumpChildren()` | Neuer Raum |
| `RoomAdminController::update`/`destroy` | `bump()` | Room-Metadaten-Change |
| `DeviceAdminController::store`/`update`/`destroy` | `bumpChildren()` | Device-CRUD |
| `DailyActiveResetCommand` | `bumpChildren()` | Loescht ChildLocations ohne MovementLog |

**Effekt**: Im Idle (alle Tabs offen, aber niemand scannt) bleibt jeder
SSE-Loop bei `Cache::get('sse:last_change_at')` hängen — das ist ein
Single-Key-Lookup in der `cache`-Tabelle, ~1 ms. **Keine** Queries gegen
`movement_log`, `alerts`, `rooms`. Bei 6 Clients → 12 lookups/s, vernachlässigbar.

Bei Scan: einmaliger Cache-Write durch den Bumper, danach pro Tick ein
DB-Poll pro Client. Nach Cursor-Update ist `currentChange == lastChangeSeen`
→ keine weiteren Polls bis zum nächsten Bump.

### 3.3 Cursor-Mechanik

Drei Cursors pro Stream-Connection:

- **`lastMovementId`** — INT, monoton steigend, kommt von `MovementLog::id` AUTO_INCREMENT
- **`lastAlertId`** — INT, analog
- **`lastRoomChangeAt`** — String (`Y-m-d H:i:s`), kommt von `Room.updated_at` (eigene Migration `add_timestamps_to_rooms_table`)

Initial-Werte beim Connect:
- `lastMovementId = MovementLog::max('id')` (oder vom `Last-Event-ID`-Header)
- `lastAlertId = Alert::max('id')`
- `lastRoomChangeAt = Room::max('updated_at') ?? '1970-01-01 00:00:00'`

**Eigenschaft**: Bei Reconnect mit `last_event_id=movement:42;alert:0` werden alle Movements ab ID 43 nachgeliefert. Bei Drop ohne Reconnect-Cursor: Stream startet beim aktuellen Maximum, Backlog geht verloren (akzeptabel, Frontend macht ohnehin `loadAllDashboardData` beim Mount).

---

## 4. Cache-Schicht

Alle Caches gehen über den **`database`-Driver** in eine Laravel-`cache`-Tabelle (InnoDB) auf derselben MariaDB. **Kein Redis**, kein Memcached.

| Key | Geschrieben von | Gelesen von | Lebensdauer |
|---|---|---|---|
| `sse:last_change_at` | `SseChangeSignal::bump()` / `bumpChildren()` | jeder SSE-Stream alle 500 ms | `Cache::forever` |
| `sse:last_children_change_at` | `SseChangeSignal::bumpChildren()` | jeder SSE-Stream (Trigger fuer Full-Refresh) | `Cache::forever` |
| Laravel-Session-Daten | `SESSION_DRIVER=database` | jeder Web-Request (für Sanctum bei Same-Origin) | 120 min |
| Queue-Jobs | `QUEUE_CONNECTION=database` | – (aktuell nicht genutzt) | – |
| Rate-Limiter | `throttle:N,1` middleware | Login + Scan endpoints | 1 min |

**Warum keine Redis-Begründung** (aus `CLAUDE.md`):
- 4–6 Clients gesamt → sehr leichte Last
- Cache-Key-Lookup gegen InnoDB unter 1 ms
- Zusätzlicher Redis-Daemon = +30 MB RAM, +Komplexität, +Failure-Mode
- Lokato läuft auf einem Pi mit Ressourcen-Constraints

---

## 5. Performance / Geschwindigkeit

### 5.1 Hot-Path-Latenzen (Messung mit lokalem Compose-Stack)

| Operation | Beobachtet | Bottleneck |
|---|---|---|
| `POST /api/v1/scan` (REST) | ~150–250 ms | `ScanIngestService` DB-Transaktion |
| `POST /api/v1/auth/login` | ~500–900 ms | bcrypt (12 rounds), unvermeidbar |
| `GET /api/v1/admin/children` | ~100 ms | 36 rows + N+1-vermieden (with location.room) |
| `GET /api/stream` initial | ~125–220 ms | SSE-Setup + initial snapshot |
| SSE Push-Latenz nach Bump | ≤ 500 ms (1 Tick) | Polling-Intervall |
| SSE Idle-Tick (Cache leer) | ~1–2 ms | `Cache::get` |

`API_SLOW_REQUEST_MS=400` in `.env` triggert die `local.WARNING: Slow API
request detected`-Logzeilen — typisch für Login (Bcrypt) und gelegentlich
SSE-Initial.

### 5.2 PHP-FPM-Worker-Bilanz

`docker/php-fpm/lokato-pool.conf`:

```ini
pm = dynamic
pm.max_children = 12
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
```

**Eine SSE-Verbindung blockiert einen Worker für bis zu 60 s**, danach wird
sie via `stream.draining` rotiert und der Worker freigegeben. Bilanz:

| Clients gleichzeitig | Permanent belegt | Frei für REST |
|---|---|---|
| 5 (Standard-Hort: 4 Tablets + 1 Dashboard) | 5 | 7 |
| 8 (mit Admin-Tab + 2 Eltern-Tabs) | 8 | 4 |
| 12 | 12 | **0** → REST-Calls queuen |
| > 12 | nginx returnt `502 Bad Gateway` |

Pro Tab (Dashboard/Tablet/Admin) ist es **eine** Verbindung — der Browser
selbst limitiert eh auf ~6 gleichzeitige Connections pro Origin, aber
EventSource ist davon ausgenommen.

### 5.3 DB-Last (geschätzt)

**Idle (5 Clients verbunden, niemand scannt)**:
- Alle 500 ms × 5 Clients = 10 `Cache::get`-Queries/s
- 1 Heartbeat-Echo alle 15 s pro Client (kein DB)
- → **~10 lightweight queries/s**, < 1% MariaDB-Auslastung

**Bei Scan (1 Scan/s über mehrere Räume)**:
- 1 Insert MovementLog
- 1 Update ChildLocation (+lock-for-update)
- 1 Update Child (wenn `is_active` toggelt)
- 1 Update Device.last_seen
- 1 Cache::set
- Pro Client beim nächsten Tick: 1 SELECT movement_log + 1 SELECT alert + 1 SELECT rooms + 1 OccupancySnapshotBuilder (2 SELECTs)
- Bei 5 Clients: ~25 Queries/Scan
- → **bei 1 Scan/s**: ~30 Queries/s, < 5% Auslastung

Realistisch (Hort-Modus): nur wenige Scans pro Minute — DB ist meistens im Idle-Zustand.

### 5.4 MariaDB-Hot-Spots

| Tabelle | Hot Operations | Optimierung |
|---|---|---|
| `cache` | `Cache::get('sse:last_change_at')` 10×/s | PK-Lookup, unter 1 ms |
| `movement_log` | INSERT bei Scan, SELECT WHERE id > X (pro SSE-Poll) | PK + auto-inc, append-only |
| `child_locations` | UPDATE bei Scan, JOIN bei OccupancySnapshot | PK = child_id, kleine Tabelle (< 50 rows) |
| `rooms` | UPDATE bei Toggle, SELECT WHERE updated_at > X | sehr kleine Tabelle (< 20 rows) |
| `children` | UPDATE bei Scan (is_active), SELECT bei admin/list | kleine Tabelle (< 100 rows) |

Kein einziger Index-Scan auf großen Tabellen im Hot-Path.

### 5.5 Skalierungs-Limits

| Limit | Aktuell | Wann erreicht? |
|---|---|---|
| PHP-FPM-Worker | 12 | ab ~12 gleichzeitigen Tabs |
| DB-Connections | ~150 (MariaDB default) | sehr weit weg |
| SSE-Push-Latenz | 500 ms | by-design, durch Polling-Intervall fix |
| RAM (Pi 4, 4 GB) | ~600 MB Stack-Total | ausreichend |

**Nicht-Skalierungs-Konstanten**: das System ist single-tenant — eine
Hort-Einrichtung pro Installation. Multi-Tenant würde Schema-Änderungen
erfordern (room_id-Scoping pro Tenant).

---

## 6. Bekannte Design-Entscheidungen

### Warum bump() im Aufrufer und nicht im Service?

`ScanIngestService` ist als reine fachliche Logik konzipiert (atomare
Transaktion, lockForUpdate, Idempotenz). Es soll **keine** Infrastruktur-
Side-Effects haben. `SseChangeSignal::bump()` ist Infrastruktur (Cache-Write)
— gehört in die Aufrufer-Schicht (Controller, Subscriber).

**Konsequenz**: Wer den Service direkt via Tinker / programmatisch ruft,
muss `SseChangeSignal::bump()` selbst aufrufen. Im Produktiv-Pfad
(MQTT-Subscriber, REST-Endpoint) ist das immer der Fall.

### Warum SSE und nicht WebSockets?

- Browser-nativ (`EventSource`), kein zusätzliches Client-SDK
- Reverse-Proxy-freundlich (HTTP-Stream, nginx kann's out-of-the-box)
- Kein zusätzlicher Daemon nötig (Laravel WS / Reverb / Soketi würde laufen müssen)
- Kosten: kein bidirektionaler Channel — der Client muss für *Schreib*-Aktionen REST machen. Für Lokato OK, weil Scans über MQTT/REST kommen, nicht über Browser-Push.

### Warum kein Pub/Sub?

Pub/Sub würde **einen** long-lived Subscriber-Prozess für viele Clients
brauchen. Aktuell hält Lokato **N** Worker (einer pro Client) für 60 s.
Bei 4–6 Clients ist das billiger als Redis + Subscriber-Process. Ab
~50 Clients wäre Pub/Sub effizienter.

### Warum 500 ms Polling-Intervall?

- Zu kurz (50 ms): Cache::get alle 50 ms × N Clients = unnötige Load, ohne UX-Gewinn (Mensch nimmt < 200 ms eh nicht wahr)
- Zu lang (5 s): spürbare Verzögerung zwischen Scan und Anzeige
- 500 ms ist der Sweet-Spot — durch das Cache-Gate eh nur 2 Cache-Reads/s pro Client

### Warum max 60 s Connection-Alter?

Browser-EventSource ist normalerweise endlos. Aber:
- nginx default `proxy_read_timeout = 60s` — würde unsere Verbindung killen, mit unsere Settings höher konfigurierbar
- PHP-FPM-Worker dauerhaft binden ist verschwenderisch
- Bei 60 s Rotation: jeder Worker dient ~30 connections/h statt einer

`stream.draining` + automatischer Reconnect durch den Browser (mit
`Last-Event-ID`) — der Cursor wird via Query-Param weitergegeben.

---

## 7. Was du im Browser-DevTools sehen kannst

**Network → `stream` → EventStream-Tab**:
```
event: stream.ready
data: {"scope":"dashboard","connected_at":"2026-06-27T15:40:00+00:00"}

event: child.moved
data: {"id":21,"child_id":1,"child":{"id":1,"name":"SCHWARZ-BLUME"},
       "from_room":{"id":2,"name":"Obergeschoss"},
       "to_room":{"id":1,"name":"Garten"},"source":"device",
       "occurred_at":"2026-06-27T15:40:12+00:00"}

event: room.occupancy.updated
data: {"room_id":1,"room_name":"Garten","current_count":1,
       "children":[{"id":1,"name":"SCHWARZ-BLUME","is_active":true,…}]}

: heartbeat 2026-06-27T15:40:15+00:00

event: stream.draining
data: {"reason":"max_connection_age_reached","reconnect":true}
```

**Network → `cache` (DB)**: nicht sichtbar (server-internal).

---

## 8. Wo du Probleme verursachen kannst (Anti-Patterns)

1. **`ScanIngestService` direkt rufen ohne `SseChangeSignal::bump()`** → SSE-Push fehlt. Lieber REST/MQTT.
2. **Eigenen Cache-Key statt `SseChangeSignal::bump()`** → SSE-Loop merkt nichts. Immer den Service nutzen.
3. **`Cache::forever('sse:…', …)` ohne TTL** → Falsche Annahme, der Singleton-Wrapper macht das schon.
4. **Lange-laufende Operationen in REST-Endpoints** → blockt einen Worker. Falls nötig: Queue-Job.
5. **REST-Calls ohne `Accept: application/json`** → Laravel-Validation redirected mit `302` statt JSON `422` (Web-Mode-Fallback).
6. **`EventSource` mit Authorization-Header bauen** → Browser ignoriert. SSE-Endpoint muss public bleiben oder Auth via Query-Param `?token=…` (aktuell ist `/api/stream` public, LAN-Trust).
7. **Mehrere `EventSource`-Instanzen pro Tab** → mehrere Worker. Stores sind Singletons → immer dasselbe `EventSource` wiederverwenden.

---

## 9. Diagnose-Cheat-Sheet

```bash
# SSE-Connect/Disconnect-Log live tail
docker compose exec php-fpm tail -f storage/logs/sse.log

# Was steht aktuell im Wake-Up-Cache?
docker compose exec php-fpm php artisan tinker --execute \
  "echo Cache::get('sse:last_change_at', 'NOT SET');"

# Letzte 5 Movements
docker compose exec php-fpm php artisan tinker --execute \
  "echo App\\Models\\MovementLog::latest('id')->take(5)->get()->toJson(JSON_PRETTY_PRINT);"

# Aktive Kinder zählen
docker compose exec php-fpm php artisan tinker --execute \
  "echo 'aktiv: '.App\\Models\\Child::where('is_active',true)->count().' / total: '.App\\Models\\Child::count();"

# Stream raw beobachten (10 Sek lang)
timeout 10 curl -sS -N http://localhost/api/stream | grep -E "^(event:|data:)"
```

## 10. Verwandte Doku

- [`docs/DEVELOPMENT.md`](DEVELOPMENT.md) — Dev-Setup mit Compose
- [`docs/PRODUCTION.md`](PRODUCTION.md) — Pi-Native-Deploy
- [`docs/CRON.md`](CRON.md) — Scheduler, Daily-Reset
- [`docs/TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — bekannte Failure-Modes
- [`CLAUDE.md`](../CLAUDE.md) — Architektur-Entscheidungen + „nicht anfassen"-Bereiche
