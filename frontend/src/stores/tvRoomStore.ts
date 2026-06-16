import { defineStore } from "pinia";
import api from "../api/axios";
import { buildApiUrl } from "@/utils/api";
import type { Child, Room } from "@/stores/dashboardDataStore";

export interface RoomOccupancyUpdatePayload {
  room_id: number;
  room_name?: string;
  current_count?: number;
  children?: Child[];
}

function parseJsonSafely<T>(value: string): T | null {
  try {
    return JSON.parse(value) as T;
  } catch (error) {
    console.warn("[TVRoomStore] Failed to parse SSE payload", error);
    return null;
  }
}

export const useTVRoomStore = defineStore("tvRoomStore", {
  state: () => ({
    rooms: null as Room[] | null,
    loading: false as boolean,
    error: null as string | null,
    sse: null as EventSource | null,
    sseConnected: false as boolean,
    reconnectAttempts: 0 as number,
    lastEventId: null as string | null,
  }),

  actions: {
    async loadRooms() {
      if (this.loading) return;

      this.loading = true;
      this.error = null;

      try {
        const response = await api.get("/rooms", { params: { include_children: true } });
        const rooms = response.data as Room[];

        this.rooms = rooms.map((room) => ({
          ...room,
          current_count: room.current_count ?? room.children?.length ?? 0,
          children: room.children ?? [],
        }));
      } catch (err) {
        this.error = err instanceof Error ? err.message : "Fehler beim Laden der Räume";
      } finally {
        this.loading = false;
      }
    },

    connectSSE() {
      if (this.sse) return;
      this.disconnectSSE();

      this.sse = new EventSource(buildApiUrl("/stream/dashboard"));

      this.sse.onopen = () => {
        this.sseConnected = true;
        this.reconnectAttempts = 0;
        console.info("[TVRoomStore] SSE connected");
      };

      this.sse.addEventListener("room.occupancy.updated", (e: MessageEvent) => {
        this.lastEventId = e.lastEventId || this.lastEventId;
        const payload = parseJsonSafely<RoomOccupancyUpdatePayload>(e.data);
        if (payload) this.handleOccupancyUpdate(payload);
      });

      this.sse.addEventListener("room.alert.raised", (e: MessageEvent) => {
        this.lastEventId = e.lastEventId || this.lastEventId;
        const payload = parseJsonSafely<unknown>(e.data);
        console.warn("[TVRoomStore] Room alert", payload);
      });

      this.sse.addEventListener("stream.draining", () => {
        console.info("[TVRoomStore] Server requested stream rotation");
        this.disconnectSSE();
        this.connectSSE();
      });

      this.sse.onerror = () => {
        this.sseConnected = false;
        this.reconnectAttempts += 1;
        console.warn("[TVRoomStore] SSE connection dropped; native retry in progress", {
          reconnectAttempts: this.reconnectAttempts,
        });
      };
    },

    disconnectSSE() {
      if (this.sse) this.sse.close();
      this.sse = null;
      this.sseConnected = false;
    },

    handleOccupancyUpdate(payload: RoomOccupancyUpdatePayload) {
      const index = this.rooms?.findIndex((room) => room.id === payload.room_id) ?? -1;
      const existingRoom = index >= 0 ? this.rooms![index] : null;

      const updatedRoom: Room = {
        id: payload.room_id,
        name: payload.room_name ?? existingRoom?.name ?? `Raum ${payload.room_id}`,
        capacity: existingRoom?.capacity,
        tolerance: existingRoom?.tolerance,
        status: existingRoom?.status,
        is_active: existingRoom?.is_active,
        current_count: payload.current_count ?? payload.children?.length ?? existingRoom?.current_count ?? 0,
        children: payload.children ?? existingRoom?.children ?? [],
      };

      if (!this.rooms) {
        this.rooms = [updatedRoom];
        return;
      }

      if (index >= 0) {
        this.rooms[index] = updatedRoom;
      } else {
        this.rooms.push(updatedRoom);
      }
    },
  },
});