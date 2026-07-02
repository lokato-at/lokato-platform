# Lokato Backend (Laravel 12)

Backend-spezifische Quick-Reference. Für Setup-Anleitungen (Dev/Prod) bitte das **Root-`README.md`** lesen — diese Datei dokumentiert nur die Backend-internen Konventionen.

---

## Code-Layout (Kurzüberblick)

```
app/
├── Console/Commands/
│   ├── MqttSubscribeCommand.php      MQTT-Listener; ruft ScanIngestService
│   └── DailyActiveResetCommand.php   Setzt children.is_active=0 (Cron)
├── Http/Controllers/Api/
│   ├── SseStreamController.php       Einziger SSE-Endpoint /api/stream
│   ├── DeviceEventController.php     REST-Scan-Fallback /api/v1/scan
│   ├── ChildrenController.php        Children-Listing + Checkout
│   ├── RoomsController.php           Rooms + Occupancy
│   ├── MovementLogController.php     Bewegungs-Historie
│   ├── DiagnosticsController.php     /api/health, /api/readiness
│   └── Admin/                        Admin-CRUD (Children/Rooms/Devices/Summary)
├── Http/Requests/
│   └── DeviceScanRequest.php         Validation für REST-Scan
├── Models/
│   ├── Child, Room, Device           Kern-Entities
│   ├── ChildLocation                 aktueller Raum eines Kindes
│   ├── MovementLog                   Bewegungs-Verlauf (append-only)
│   ├── Alert                         Warnungen
│   └── AppRuntimeState               Key/Value-Store (z. B. last_daily_reset_at)
├── Services/
│   └── ScanIngestService.php         ⚠ FACHLICHER KERN — nicht anfassen
└── Support/
    ├── AppLogger.php                 Strukturiertes Logging mit Sanitization
    ├── OccupancySnapshotBuilder.php  Aggregations-Queries pro Raum
    └── SseChangeSignal.php           Cache-Wakeup für SSE-Loops
```

## Routen-Übersicht (`routes/api.php`)

Public (kein Auth):

| Methode | Pfad | Controller |
|---|---|---|
| POST | `/api/v1/scan` | `DeviceEventController@store` |
| GET | `/api/v1/children`, `/children/{child}` | `ChildrenController@index`/`show` |
| GET | `/api/v1/movement-log`, `/children/{child}/movement-log` | `MovementLogController` |
| GET | `/api/v1/rooms`, `/rooms/{room}/occupancy` | `RoomsController` |
| POST | `/api/v1/auth/login` | `AuthController@login` (throttle 10/min) |
| GET | `/api/stream[?room=X&initial=1&last_event_id=…]` | `SseStreamController@stream` |
| GET | `/api/health`, `/api/readiness`, `/api/diagnostics` | `DiagnosticsController` |

Auth-protected (`auth:sanctum`):

| Methode | Pfad | Controller |
|---|---|---|
| POST | `/api/v1/children/{child}/checkout` | `ChildrenController@checkout` |
| POST | `/api/v1/auth/logout`, GET `/auth/me` | `AuthController` |
| GET | `/api/v1/admin/summary` | `Admin\AdminSummaryController` |
| GET/POST/PUT/DELETE | `/api/v1/admin/{children,rooms,devices}` | `Admin\…AdminController` (apiResource) |
| POST/DELETE | `/api/v1/admin/children/{child}/photo` | `Admin\ChildAdminController@uploadPhoto`/`deletePhoto` |

## SSE-Endpoint (Phase-2-Refactor)

Ein einziger Endpoint, drei Modi gesteuert über Query-Params:

| URL | Modus | Sichtbare Events |
|---|---|---|
| `/api/stream` | Dashboard (alle Räume) | `child.moved`, `room.occupancy.updated` (alle Räume), `room.alert.raised` (alle) |
| `/api/stream?room=3&initial=1` | Raumtablet (gescopet auf Raum 3) | `child.moved` (nur Raum 3), `room.occupancy.updated` (Raum 3), `room.alert.raised` (Raum 3); plus 1× initial `room.occupancy.updated` direkt nach Connect |
| `/api/stream?last_event_id=movement:42;alert:5` | Reconnect-Modus | wie Dashboard, aber resumed ab Cursor |

Loop-Verhalten:
- 500 ms-Polling, **aber** mit Cache-Gate (`SseChangeSignal::lastChangeAt()`). Solange seit dem letzten Tick kein Scan eingegangen ist, werden die DB-Queries übersprungen → **Idle-DB-Last = 0**.
- Heartbeat-Kommentar (`: heartbeat …`) alle 15 s, damit Proxies die Verbindung nicht killen.
- Auto-Drain via `stream.draining`-Event nach `SSE_MAX_CONNECTION_SECONDS` (default 60). Client soll danach reconnecten.

Cache-Bump-Aufrufer (`ScanIngestService` selbst bleibt unangetastet):

- `bump()` — Movement-getriebene Aenderungen (SSE-Loop pollt MovementLog/Alert/Room):
  - `MqttSubscribeCommand` nach erfolgreichem `ingestScan()`
  - `DeviceEventController` nach erfolgreichem `ingestScan()`
  - `ChildrenController::checkout()` nach Checkout
  - `ChildAdminController::update()` (schreibt bei is_active-Toggle MovementLog)
  - `RoomAdminController::update()`/`destroy()` (Room-Metadaten-Change)
- `bumpChildren()` — Aenderungen ohne MovementLog (triggert Full-Refresh aller Raum-Snapshots):
  - `RoomAdminController::store()` (neuer Raum)
  - `ChildAdminController::store()`/`destroy()`/`uploadPhoto()`/`deletePhoto()`
  - `DeviceAdminController::store()`/`update()`/`destroy()`
  - `DailyActiveResetCommand` (loescht ChildLocations, schreibt keine MovementLogs)

## Logging

Custom-Channels in `config/logging.php`:

| Channel | Datei | Wofür |
|---|---|---|
| `scan` | `storage/logs/scan.log` | MQTT-Events, Scan-Diagnostik, DB-Diagnostik (alle `AppLogger`-Komponenten `mqtt`/`scan`/`db`) |
| `sse` | `storage/logs/sse.log` | SSE-Connect/Disconnect-Events |
| `cron` | `storage/logs/cron.log` | Daily-Reset-Events |
| `stack` → `single` | `storage/logs/laravel.log` | Default-Fallback |

Schreibmuster:
```php
use App\Support\AppLogger;

AppLogger::event('mqtt', 'mqtt_message_received', ['topic' => $topic, 'len' => $len]);
AppLogger::exception('cron', 'daily_reset_failed', $e, ['reset_date' => $date]);
```

`AppLogger::CHANNEL_MAP` routet `mqtt|scan|db` → `scan.log`, `sse` → `sse.log`, `cron` → `cron.log`. Sensible Keys (`password`, `secret`, `token`, …) werden automatisch durch `[REDACTED]` ersetzt.

Verbositäts-Flags in `.env`:
- `LOG_ENABLED=true|false` (Master-Schalter; `error`/`critical` werden trotzdem geloggt)
- `LOG_FORMAT=pretty|json`
- `MQTT_DIAGNOSTIC_LOGS`, `SCAN_DIAGNOSTIC_LOGS`, `CRON_LOGS`, `DB_DIAGNOSTIC_LOGS` (für jeweils zusätzliche Debug-Events)
- `LOG_LEVEL=info|debug|warning|error`

## Wichtige `.env`-Variablen

| Bereich | Variablen | Anmerkung |
|---|---|---|
| App | `APP_KEY`, `APP_URL`, `APP_ENV`, `APP_DEBUG`, `APP_TIMEZONE` | `APP_KEY` per `php artisan key:generate` |
| DB | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | **Compose: `DB_HOST=db`**, Pi: `DB_HOST=127.0.0.1`, Host-Mode-Dev: `127.0.0.1` |
| MQTT | `MQTT_HOST`, `MQTT_PORT`, `MQTT_TOPIC_SCAN`, `MQTT_CLIENT_ID`, `MQTT_QOS` | **Compose: `MQTT_HOST=mqtt`**, Pi: `127.0.0.1` |
| MQTT-Auth | `MQTT_AUTH_USERNAME`, `MQTT_AUTH_PASSWORD` | **`=null` schreiben (nicht leer lassen)**, sonst crasht php-mqtt-Client |
| MQTT-Reconnect | `MQTT_AUTO_RECONNECT_ENABLED`, `_MAX_ATTEMPTS`, `_DELAY` | sinnvolle Defaults in `.env.raspi.example` |
| Driver | `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | alle drei auf `database` (kein Redis im Setup) |
| API | `API_SLOW_REQUEST_MS`, `SSE_MAX_CONNECTION_SECONDS` | Performance-Limits |
| Diag | `DIAGNOSTICS_ENABLED` | aktiviert `/api/diagnostics` |

Templates:
- `.env.example` — Host-Mode-Dev (lokales `php artisan serve`)
- `.env.raspi.example` — Pi-Prod (native apt-Installation)

## Artisan-Befehle

| Befehl | Wofür |
|---|---|
| `php artisan migrate --force` | Migrations einspielen (Pi-Prod: `--force` zwingend) |
| `php artisan db:seed --force` | Demo-Daten (3 Räume, 36 Kinder, 3 Devices) |
| `php artisan mqtt:subscribe` | MQTT-Subscriber als Vordergrund-Prozess (auf dem Pi via systemd) |
| `php artisan mqtt:subscribe --once --debug` | Genau einen Scan verarbeiten + ausführliches Logging |
| `php artisan children:daily-active-reset` | Manueller Daily-Reset (Cron oder Test) |
| `php artisan key:generate --force` | `APP_KEY` neu setzen |
| `php artisan config:clear` / `config:cache` | Config-Cache leeren/bauen — **zwingend nach jeder `.env`-Änderung** |
| `php artisan route:list` | Alle Routen + Controller |
| `php artisan tinker` | REPL — z. B. `App\Models\Child::count()` |

## Daily-Reset (Cron)

`DailyActiveResetCommand` (`children:daily-active-reset`) setzt alle `children.is_active` auf `false` und merkt sich Datum + Zeit in `app_runtime_state`. Loggt `daily_reset_started` / `daily_reset_finished` / `daily_reset_failed` in `cron.log`.

Schedule-Definition liegt in `routes/console.php` (Laravel-Scheduler `dailyAt('01:00')` Europe/Vienna). Damit das tatsächlich ausgelöst wird, muss System-Cron minütlich `php artisan schedule:run` aufrufen. Die vollständige Setup-Anleitung steht im **Root-`README.md` → Abschnitt „Cron-Jobs auf dem Pi"**.

Manuell triggern (z. B. zum Testen):
```bash
sudo -u www-data php /var/www/lokato/backend/artisan children:daily-active-reset
```

## Tests

```bash
php artisan test                  # Feature-Suite
php artisan test --filter Scan    # Nur Scan-Tests
```

## Was NICHT anfassen

- `app/Services/ScanIngestService.php` — fachlicher Kern, atomare Transaktion mit Lock-for-Update
- Modell-Geschäftsregeln (`Child`, `Room`, `ChildLocation`, `MovementLog`, `Alert`)
- `MqttSubscribeCommand`-Validierung — strukturelle Logik bleibt; einzige zugelassene Erweiterung: `SseChangeSignal::bump()`-Aufruf nach erfolgreichem Ingest

Siehe `../CLAUDE.md` für die vollständige Liste der Architekturentscheidungen.
