# Lokato Frontend (Vue 3 + TypeScript + Pinia)

Frontend-spezifische Quick-Reference. Für Setup-Anleitungen (Dev/Prod) bitte das **Root-`README.md`** lesen — diese Datei dokumentiert nur Frontend-interne Konventionen. Eine ausführliche fachliche Beschreibung steht in [`frontend_explanation.md`](./frontend_explanation.md).

---

## Stack

- Vue 3.5 (Composition API), TypeScript, Pinia, Vite 7
- Router im Hash-Modus (`#/dashboard`, `#/tablet/:roomId`, `#/admin/…`)
- Kommunikation mit Backend:
  - REST via `axios` (zentral in `src/api/axios.ts`)
  - SSE via `EventSource` (in den Pinia-Stores)

## Code-Layout

```
src/
├── App.vue                Layout-Shell, Navigation, Hide-on-Tablet
├── main.ts                Pinia + Router-Init
├── api/
│   └── axios.ts           axios-Instance, baseURL aus apiBaseUrl
├── router/
│   └── index.ts           Hash-Routes (Dashboard, Tablet, Admin)
├── stores/
│   ├── dashboardDataStore.ts    Dashboard: REST + SSE /api/stream
│   ├── roomTabletStore.ts       Raumtablet: REST + SSE /api/stream?room=X
│   ├── adminDataStore.ts        Admin: CRUD via REST + Retry-Logik
│   └── devDataStore.ts          Dev-Spielwiese (alle Endpoints)
├── utils/
│   └── api.ts             buildApiUrl() — erkennt /stream-Pfade
├── views/
│   ├── DashboardView.vue
│   ├── AdminView.vue
│   ├── admin/             {Children,Rooms,Devices,Movement}AdminView.vue
│   └── tablet/RoomTabletView.vue
└── __tests__/
    └── App.spec.ts        Vitest-Smoke-Test für App-Shell
```

## ENV-Variablen

| Variable | Default | Bedeutung |
|---|---|---|
| `VITE_API_BASE_URL` | `/api/v1` | Same-Origin-Pfad (relative URL). Beim Compose-Setup geht das via nginx an Laravel; in Dev mit direkter Vite-Verbindung (`http://localhost:5173`) übernimmt der Vite-Proxy aus `vite.config.ts` das Routing. |

Wenn du gegen ein Remote-Backend testen willst, kannst du `VITE_API_BASE_URL=http://192.168.1.100/api/v1` setzen — dann sind die Calls absolut.

Templates:
- `.env.example` — Default für Win-Dev und Compose
- `.env.raspi.example` — Pi-Prod (identisch `/api/v1`, weil Same-Origin)

## SSE — Wie die Stores das nutzen

Beide SSE-Stores (`dashboardDataStore`, `roomTabletStore`) haben das gleiche Pattern:

```typescript
// Aus dashboardDataStore.ts
const params = new URLSearchParams();
if (this.lastEventId) params.set("last_event_id", this.lastEventId);
const qs = params.toString();
this.sse = new EventSource(buildApiUrl(qs ? `/stream?${qs}` : "/stream"));

this.sse.addEventListener("child.moved", (e) => { … });
this.sse.addEventListener("room.occupancy.updated", (e) => { … });
this.sse.addEventListener("room.alert.raised", (e) => { … });
this.sse.addEventListener("stream.draining", () => {
  this.disconnectSSE();
  this.connectSSE();  // Reconnect mit gemerkter last_event_id
});
```

Wichtige Details:
- `lastEventId` wird in jedem Event-Handler aus `e.lastEventId` gepflegt.
- Beim manuellen Reconnect (z. B. nach `stream.draining`) wird `last_event_id` als Query-Param mitgegeben — der Browser setzt den `Last-Event-ID`-Header **nicht** automatisch bei einer neu konstruierten `EventSource`.
- `roomTabletStore` schickt zusätzlich `?room=X&initial=1` — der Server filtert serverseitig und schickt direkt nach Connect einen initialen Occupancy-Snapshot.

## `buildApiUrl()` (`utils/api.ts`)

Hilfsfunktion zum Konstruieren der API-URLs. Berücksichtigt zwei Fälle:
- Normale Pfade: `VITE_API_BASE_URL` + Pfad
- Stream-Pfade (`/stream`, `/stream/`, `/stream?…`): `VITE_API_BASE_URL` **ohne** `/v1`-Suffix + Pfad — weil SSE-Routen unter `/api/stream` liegen, REST unter `/api/v1/…`.

Path-Detection via Regex `^\/stream(?:\/|$|\?)/`.

## NPM-Scripts

| Script | Zweck |
|---|---|
| `npm run dev` | Vite-Dev-Server mit HMR, hört per Default auf `0.0.0.0:5173` |
| `npm run build` | TypeScript-Check + Production-Build nach `dist/` |
| `npm run preview` | Lokales Preview des Production-Builds (Dev-Tool, **nicht** für Prod) |
| `npm run type-check` | `vue-tsc --build` ohne JS-Output |
| `npm run lint` | ESLint mit `--fix --cache` |
| `npm run format` | Prettier über `src/` |
| `npm run test:unit` | Vitest |

## Vite-Dev-Proxy

`vite.config.ts` hat einen Fallback-Proxy:
```ts
proxy: { '/api': 'http://localhost:8001' }
```

Dieser greift nur, wenn du den **Vite-Dev-Server direkt** unter `http://localhost:5173` aufrufst (Legacy-Variante mit `php artisan serve` auf 8001). Bei Compose-Setup nutzt du `http://localhost` (= nginx auf Port 80) — dann übernimmt nginx das `/api`-Routing und der Vite-Proxy ist umgangen.

## Tests

```powershell
npm run test:unit          # Vitest single-run
npm run test:unit -- --watch  # Watch-Modus
```

## Was NICHT anfassen

- Event-Namen in den SSE-Listenern (`child.moved`, `room.occupancy.updated`, `room.alert.raised`, `stream.draining`, `stream.ready`) — Strings sind Vertrag mit dem Backend.
- `OccupancyUpdatePayload` / `RoomOccupancyUpdatePayload` Schemas — Server emittiert exakt diese Felder.

Siehe `../CLAUDE.md` für Architekturentscheidungen.
