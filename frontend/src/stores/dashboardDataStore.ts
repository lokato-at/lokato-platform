import { defineStore } from "pinia";
import api from "../api/axios";
import { buildRealtimeUrl } from "@/utils/api";

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
  child?: Child | null;
  child_id?: number;
  to_room_id?: number | null;
  [key: string]: unknown;
}

interface RealtimeEnvelope<T = unknown> {
  event: string;
  data: T;
}

function parseJsonSafely<T>(value: string): T | null {
  try {
    return JSON.parse(value) as T;
  } catch (error) {
    console.warn("[DashboardStore] Failed to parse realtime payload", error);
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
    socket: null as WebSocket | null,
    socketConnected: false as boolean,
    reconnectAttempts: 0 as number,
    reconnectTimer: null as ReturnType<typeof setTimeout> | null,
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

    connectRealtime() {
      if (this.socket && (this.socket.readyState === WebSocket.CONNECTING || this.socket.readyState === WebSocket.OPEN)) {
        return;
      }

      if (this.reconnectTimer) {
        clearTimeout(this.reconnectTimer);
        this.reconnectTimer = null;
      }

      const wsUrl = buildRealtimeUrl("/ws");
      this.socket = new WebSocket(wsUrl);

      this.socket.onopen = () => {
        this.socketConnected = true;
        this.reconnectAttempts = 0;
        console.info("[DashboardStore] realtime socket connected", { wsUrl });
      };

      this.socket.onmessage = (e: MessageEvent<string>) => {
        const envelope = parseJsonSafely<RealtimeEnvelope>(e.data);
        if (!envelope) return;

        switch (envelope.event) {
          case "child.moved": {
            const payload = envelope.data as Movement;
            this.handleChildMoved(payload);
            break;
          }
          case "room.occupancy.updated": {
            const payload = envelope.data as OccupancyUpdatePayload;
            this.handleOccupancyUpdate(payload);
            break;
          }
          default:
            break;
        }
      };

      this.socket.onerror = () => {
        this.socketConnected = false;
      };

      this.socket.onclose = () => {
        this.socketConnected = false;
        this.scheduleReconnect();
      };
    },

    scheduleReconnect() {
      this.reconnectAttempts += 1;
      const delayMs = Math.min(1000 * 2 ** Math.min(this.reconnectAttempts, 5), 15000);

      this.reconnectTimer = setTimeout(async () => {
        await this.fetchAllDashboardData(true);
        this.connectRealtime();
      }, delayMs);
    },

    disconnectRealtime() {
      if (this.reconnectTimer) {
        clearTimeout(this.reconnectTimer);
        this.reconnectTimer = null;
      }

      if (this.socket) {
        this.socket.onclose = null;
        this.socket.close();
      }

      this.socket = null;
      this.socketConnected = false;
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
