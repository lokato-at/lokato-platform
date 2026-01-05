import { defineStore } from "pinia";
import api from "../api/axios";

/*
|--------------------------------------------------------------------------
| DashboardDataStore
|--------------------------------------------------------------------------
| Dieser Store verwaltet alle Daten, die das öffentliche Dashboard benötigt:
|
|  - Liste aller Räume (GET /rooms)
|  - Aktuelle Belegung (Occupancy) je Raum (GET /rooms/{id}/occupancy)
|  - Die letzten Bewegungen (Movement Log – GET /movement-log)
|
| Zusätzlich:
|  - Live-Updates via Server-Sent Events (SSE)
|
| Der Store verwendet **nur Public API Endpoints**, keine Admin-Routen.
|
| Aufgerufen wird fetchAllDashboardData() z. B. in DashboardView.vue,
| normalerweise im onMounted().
*/
export const useDashboardDataStore = defineStore("dashboardDataStore", {
  state: () => ({
    /*
    |--------------------------------------------------------------------------
    | STATE
    |--------------------------------------------------------------------------
    */

    /*
     * rooms
     * Liste aller Räume aus GET /rooms
     * Beispiel einer Raumstruktur:
     * {
     *   id: 1,
     *   name: "Turnsaal",
     *   capacity: 30,
     *   tolerance: 5,
     *   status: { over_capacity: false, within_tolerance: true },
     *   current_count: 12
     * }
     */
    rooms: null as null | unknown[],

    /*
     * occupancy
     * Belegung aller Räume, gemapped nach Raum-ID:
     *
     * occupancy[raumId] = {
     *   room: {...},
     *   current_count: 3,
     *   children: [
     *     { id: 4, name: "Anna", tracker_uid: "...", ... }
     *   ]
     * }
     *
     * Warum Map? → schnell, direkt und unkompliziert im Template nutzbar
     */
    occupancy: {} as Record<number, unknown>,

    /*
     * latestMovements
     * Letzte 5 Bewegungen aus GET /movement-log
     * Die API ist paginiert und liefert:
     *
     * {
     *   current_page: 1,
     *   data: [ {...}, {...}, ... ],
     *   total: ...
     * }
     *
     * Wir extracten nur data.slice(0,5)
     */
    latestMovements: [] as unknown[],

    /*
     * loading
     * true → Dashboard lädt gerade Daten
     * false → Daten fertig geladen
     */
    loading: false as boolean,

    /*
     * error
     * Falls während des Fetchens etwas schiefgeht,
     * landet die Fehlermeldung hier.
     */
    error: null as string | null,

    /*
     * sse
     * Aktive Server-Sent-Events Verbindung
     */
    sse: null as EventSource | null,

    /*
     * sseConnected
     * Statusflag für Debug / UI
     */
    sseConnected: false as boolean,
  }),

  actions: {
    /*
    |--------------------------------------------------------------------------
    | fetchAllDashboardData()
    |--------------------------------------------------------------------------
    | Lädt ALLE notwendigen Dashboarddaten in der richtigen Reihenfolge:
    |
    |   (1) Räume
    |   (2) Occupancy für jeden Raum
    |   (3) Movement Log (letzte 5 Einträge)
    |
    | Wird normalerweise bei Dashboard-Start ausgeführt.
    | Danach hält sich das Dashboard via SSE aktuell.
    */
    async fetchAllDashboardData() {
      this.loading = true;
      this.error = null;

      try {
        /*
        ----------------------------------------------------------------------
        1) Räume laden
        GET /rooms
        ----------------------------------------------------------------------
        */
        const roomsRes = await api.get("/rooms");
        this.rooms = roomsRes.data; // volle Liste der Räume

        /*
        ----------------------------------------------------------------------
        2) Occupancy für jeden Raum laden
        GET /rooms/{id}/occupancy
        Wir benutzen bewusst keine parallelen Promises,
        um Fehler je Raum einzeln abzufangen.
        ----------------------------------------------------------------------
        */
        const occMap: Record<number, unknown> = {};

        for (const room of roomsRes.data) {
          try {
            const occRes = await api.get(`/rooms/${room.id}/occupancy`);
            occMap[room.id] = occRes.data;
          } catch (innerErr) {
            console.warn(
              `⚠ Fehler beim Laden der Occupancy für Raum ${room.id}`,
              innerErr
            );
          }
        }

        this.occupancy = occMap;

        /*
        ----------------------------------------------------------------------
        3) Movement Log (letzte 5)
        GET /movement-log
        Die API ist paginiert → wir nutzen nur data.slice(0,5)
        ----------------------------------------------------------------------
        */
        const movRes = await api.get("/movement-log");
        this.latestMovements = movRes.data?.data?.slice(0, 5) || [];

      } catch (err) {
        this.error =
          err instanceof Error
            ? err.message
            : "Unbekannter Fehler im Dashboard-Store";
      } finally {
        this.loading = false;
      }
    },

    /*
    |--------------------------------------------------------------------------
    | connectSSE()
    |--------------------------------------------------------------------------
    | Baut die Server-Sent-Events Verbindung zum Backend auf.
    | Authentifizierung erfolgt über Laravel Sanctum (Cookies).
    |
    | Endpoint:
    |   GET http://localhost:8001/api/stream/dashboard
    */
    connectSSE() {
      if (this.sse) return;

      console.info("[DashboardStore] Connecting SSE…");

      this.sse = new EventSource(
        "http://localhost:8001/api/stream/dashboard",
        // { withCredentials: true }
      );

      this.sse.onopen = () => {
        this.sseConnected = true;
        console.info("[DashboardStore] SSE connected");
      };

      /*
      ----------------------------------------------------------------------
      child.moved
      → Neue Bewegung → latestMovements aktualisieren
      ----------------------------------------------------------------------
      */
      this.sse.addEventListener("child.moved", (e: MessageEvent) => {
        const payload = JSON.parse(e.data);
        this.handleChildMoved(payload);
      });

      /*
      ----------------------------------------------------------------------
      room.occupancy.updated
      → Snapshot-Update für einen Raum
      ----------------------------------------------------------------------
      */
      this.sse.addEventListener("room.occupancy.updated", (e: MessageEvent) => {
        const payload = JSON.parse(e.data);
        this.handleOccupancyUpdate(payload);
      });

      /*
      ----------------------------------------------------------------------
      room.alert.raised
      → Optional (Badge, Toast, Log, ...)
      ----------------------------------------------------------------------
      */
      this.sse.addEventListener("room.alert.raised", (e: MessageEvent) => {
        const payload = JSON.parse(e.data);
        console.warn("[Dashboard ALERT]", payload);
      });

      this.sse.onerror = () => {
        console.warn("[DashboardStore] SSE error – reconnecting in 3s");
        this.disconnectSSE();
        setTimeout(() => this.connectSSE(), 3000);
      };
    },


    /*
    |--------------------------------------------------------------------------
    | disconnectSSE()
    |--------------------------------------------------------------------------
    | Trennt die SSE-Verbindung sauber (z. B. beim Verlassen der View)
    */
    disconnectSSE() {
      if (this.sse) {
        this.sse.close();
      }

      this.sse = null;
      this.sseConnected = false;
      console.info("[DashboardStore] SSE disconnected");
    },

    /*
    |--------------------------------------------------------------------------
    | handleChildMoved()
    |--------------------------------------------------------------------------
    | Fügt eine neue Bewegung vorne in die Liste ein
    | und begrenzt sie auf die letzten 5 Einträge.
    */
    handleChildMoved(movement: unknown) {
      this.latestMovements.unshift(movement);

      if (this.latestMovements.length > 5) {
        this.latestMovements.length = 5;
      }
    },

    /*
    |--------------------------------------------------------------------------
    | handleOccupancyUpdate()
    |--------------------------------------------------------------------------
    | Ersetzt die Occupancy eines Raumes durch den aktuellen Snapshot
    | und synchronisiert optional den current_count im rooms-Array.
    */
    handleOccupancyUpdate(payload: unknown) {
      const roomId = payload.room_id;

      // Snapshot ersetzen
      this.occupancy[roomId] = payload;

      // optional: rooms[] current_count synchronisieren
      if (this.rooms) {
        const room = this.rooms.find(r => r.id === roomId);
        if (room) {
          room.current_count = payload.children?.length ?? 0;
        }
      }
    },
  },
});
