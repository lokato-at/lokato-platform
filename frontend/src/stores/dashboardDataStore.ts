import { defineStore } from "pinia";
import api from "../api/axios";

// ---------------------------------------------------------------------------
// Types (minimal, damit vue-tsc/tsc nicht mehr auf unknown/{} meckert)
// ---------------------------------------------------------------------------
export interface Child {
  id: number;
  name: string;
  tracker_uid?: string | null;
  photo_url?: string | null;
}

export interface Room {
  id: number;
  name: string;
  capacity?: number;
  tolerance?: number;
  status?: {
    over_capacity?: boolean;
    within_tolerance?: boolean;
  };
  current_count?: number;
  children?: Child[];
}

export interface OccupancySnapshot {
  room?: Room;
  current_count?: number;
  children: Child[];
}

export interface OccupancyUpdatePayload extends OccupancySnapshot {
  room_id: number;
}

// Movement ist in der UI meist nur "anzeigbar" → wir lassen extra Felder zu
export interface Movement {
  id?: number;
  occurred_at?: string;
  room?: Room;
  child?: Child;
  [key: string]: unknown;
}

export const useDashboardDataStore = defineStore("dashboardDataStore", {
  state: () => ({
    rooms: null as null | Room[],
    occupancy: {} as Record<number, OccupancySnapshot>,
    latestMovements: [] as Movement[],
    loading: false as boolean,
    error: null as string | null,
    sse: null as EventSource | null,
    sseConnected: false as boolean,
  }),

  actions: {
    async fetchAllDashboardData() {
      this.loading = true;
      this.error = null;

      try {
        const roomsRes = await api.get("/rooms");
        const rooms = roomsRes.data as Room[];
        this.rooms = rooms;

        const occMap: Record<number, OccupancySnapshot> = {};

        for (const room of rooms) {
          try {
            const occRes = await api.get(`/rooms/${room.id}/occupancy`);
            occMap[room.id] = occRes.data as OccupancySnapshot;
          } catch (innerErr) {
            console.warn(`⚠ Fehler beim Laden der Occupancy für Raum ${room.id}`, innerErr);
          }
        }

        this.occupancy = occMap;

        const movRes = await api.get("/movement-log");
        const page = movRes.data as { data?: Movement[] };
        this.latestMovements = page.data?.slice(0, 5) ?? [];
      } catch (err) {
        this.error = err instanceof Error ? err.message : "Unbekannter Fehler im Dashboard-Store";
      } finally {
        this.loading = false;
      }
    },

    connectSSE() {
      if (this.sse) return;

      console.info("[DashboardStore] Connecting SSE…");

      this.sse = new EventSource("http://localhost:8001/api/stream/dashboard");

      this.sse.onopen = () => {
        this.sseConnected = true;
        console.info("[DashboardStore] SSE connected");
      };

      this.sse.addEventListener("child.moved", (e: MessageEvent) => {
        const payload = JSON.parse(e.data) as Movement;
        this.handleChildMoved(payload);
      });

      this.sse.addEventListener("room.occupancy.updated", (e: MessageEvent) => {
        const payload = JSON.parse(e.data) as OccupancyUpdatePayload;
        this.handleOccupancyUpdate(payload);
      });

      this.sse.addEventListener("room.alert.raised", (e: MessageEvent) => {
        const payload = JSON.parse(e.data) as unknown;
        console.warn("[Dashboard ALERT]", payload);
      });

      this.sse.onerror = () => {
        console.warn("[DashboardStore] SSE error – reconnecting in 3s");
        this.disconnectSSE();
        setTimeout(() => this.connectSSE(), 3000);
      };
    },

    disconnectSSE() {
      if (this.sse) this.sse.close();
      this.sse = null;
      this.sseConnected = false;
      console.info("[DashboardStore] SSE disconnected");
    },

    handleChildMoved(movement: Movement) {
      this.latestMovements.unshift(movement);
      if (this.latestMovements.length > 5) this.latestMovements.length = 5;
    },

    handleOccupancyUpdate(payload: OccupancyUpdatePayload) {
      const roomId = payload.room_id;

      this.occupancy[roomId] = payload;

      if (this.rooms) {
        const room = this.rooms.find((r) => r.id === roomId);
        if (room) {
          room.current_count = payload.children?.length ?? 0;
        }
      }
    },
  },
});
