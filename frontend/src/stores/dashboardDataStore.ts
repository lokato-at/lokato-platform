import { defineStore } from "pinia";
import api from "../api/axios";
import { buildApiUrl } from "@/utils/api";

export interface Child {
  id: number;
  name: string;
  tracker_uid?: string | null;
  photo_url?: string | null;
  updated_at?: string | null;
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
  room_name?: string;
}

export interface Movement {
  id?: number;
  occurred_at?: string;
  from_room?: { id: number; name: string } | null;
  to_room?: { id: number; name: string } | null;
  child?: Child;
  child_id?: number;
  to_room_id?: number | null;
  [key: string]: unknown;
}

function parseJsonSafely<T>(value: string): T | null {
  try {
    return JSON.parse(value) as T;
  } catch (error) {
    console.warn("[DashboardStore] Failed to parse SSE payload", error);
    return null;
  }
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
    reconnectAttempts: 0 as number,
    lastEventId: null as string | null,
  }),

  actions: {
    async fetchAllDashboardData(force = false) {
      if (this.loading) return;
      if (!force && this.rooms && this.latestMovements.length > 0) return;

      this.loading = true;
      this.error = null;

      try {
        const [roomsRes, movRes] = await Promise.all([
          api.get("/rooms", { params: { include_children: true } }),
          api.get("/movement-log", { params: { per_page: 5 } }),
        ]);

        const rooms = roomsRes.data as Room[];
        this.rooms = rooms;
        this.occupancy = rooms.reduce<Record<number, OccupancySnapshot>>((acc, room) => {
          acc[room.id] = {
            room,
            current_count: room.current_count ?? room.children?.length ?? 0,
            children: room.children ?? [],
          };
          return acc;
        }, {});

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

      this.sse = new EventSource(buildApiUrl("/stream/dashboard"));

      this.sse.onopen = () => {
        this.sseConnected = true;
        this.reconnectAttempts = 0;
        console.info("[DashboardStore] SSE connected");
      };

      this.sse.addEventListener("child.moved", (e: MessageEvent) => {
        this.lastEventId = e.lastEventId || this.lastEventId;
        const payload = parseJsonSafely<Movement>(e.data);
        if (payload) this.handleChildMoved(payload);
      });

      this.sse.addEventListener("room.occupancy.updated", (e: MessageEvent) => {
        this.lastEventId = e.lastEventId || this.lastEventId;
        const payload = parseJsonSafely<OccupancyUpdatePayload>(e.data);
        if (payload) this.handleOccupancyUpdate(payload);
      });

      this.sse.addEventListener("room.alert.raised", (e: MessageEvent) => {
        this.lastEventId = e.lastEventId || this.lastEventId;
        const payload = parseJsonSafely<unknown>(e.data);
        console.warn("[Dashboard ALERT]", payload);
      });

      this.sse.addEventListener("stream.draining", () => {
        console.info("[DashboardStore] Server requested stream rotation");
        this.disconnectSSE();
        this.connectSSE();
      });

      this.sse.onerror = () => {
        this.sseConnected = false;
        this.reconnectAttempts += 1;
        console.warn("[DashboardStore] SSE connection dropped; native retry in progress", {
          reconnectAttempts: this.reconnectAttempts,
        });
      };
    },

    disconnectSSE() {
      if (this.sse) this.sse.close();
      this.sse = null;
      this.sseConnected = false;
    },

    handleChildMoved(movement: Movement) {
      if (movement.id && this.latestMovements.some((entry) => entry.id === movement.id)) {
        return;
      }

      this.latestMovements.unshift(movement);
      if (this.latestMovements.length > 5) this.latestMovements.length = 5;
    },

    handleOccupancyUpdate(payload: OccupancyUpdatePayload) {
      const roomId = payload.room_id;
      const nextChildren = payload.children ?? [];
      this.occupancy[roomId] = {
        room: this.rooms?.find((room) => room.id === roomId),
        current_count: payload.current_count ?? nextChildren.length,
        children: nextChildren,
      };

      if (this.rooms) {
        const room = this.rooms.find((r) => r.id === roomId);
        if (room) {
          room.current_count = payload.current_count ?? nextChildren.length;
          room.children = nextChildren;
        }
      }
    },
  },
});
