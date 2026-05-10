import { defineStore } from "pinia";
import api from "../api/axios";
import { buildApiUrl } from "@/utils/api";
import type { Child, Room } from "@/stores/dashboardDataStore";

type RoomSnapshot = {
  room: Room | null;
  current_count: number;
  children: Child[];
};

export interface RoomOccupancyPayload {
  room: Room;
  current_count: number;
  children: Child[];
}

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
    console.warn("[RoomTabletStore] Failed to parse SSE payload", error);
    return null;
  }
}

export const useRoomTabletStore = defineStore("roomTabletStore", {
  state: () => ({
    roomId: null as number | null,
    snapshot: {
      room: null,
      current_count: 0,
      children: [] as Child[],
    } as RoomSnapshot,
    loading: false as boolean,
    error: null as string | null,
    sse: null as EventSource | null,
    sseConnected: false as boolean,
    reconnectAttempts: 0 as number,
    lastEventId: null as string | null,
  }),

  actions: {
    async loadRoomSnapshot(roomId: number) {
      if (this.loading) return;

      this.loading = true;
      this.error = null;

      try {
        const response = await api.get(`/rooms/${roomId}/occupancy`);
        const payload = response.data as RoomOccupancyPayload;

        this.roomId = roomId;
        this.snapshot = {
          room: payload.room,
          current_count: payload.current_count ?? payload.children?.length ?? 0,
          children: payload.children ?? [],
        };
      } catch (err) {
        this.error = err instanceof Error ? err.message : "Unknown error loading room";
      } finally {
        this.loading = false;
      }
    },

    connectSSE(roomId: number) {
      if (this.sse && this.roomId === roomId) return;
      this.disconnectSSE();

      this.roomId = roomId;
      this.sse = new EventSource(buildApiUrl(`/stream/room/${roomId}`));

      this.sse.onopen = () => {
        this.sseConnected = true;
        this.reconnectAttempts = 0;
        console.info("[RoomTabletStore] SSE connected");
      };

      this.sse.addEventListener("room.occupancy.updated", (e: MessageEvent) => {
        this.lastEventId = e.lastEventId || this.lastEventId;
        const payload = parseJsonSafely<RoomOccupancyUpdatePayload>(e.data);
        if (payload) this.handleOccupancyUpdate(payload);
      });

      this.sse.addEventListener("room.alert.raised", (e: MessageEvent) => {
        this.lastEventId = e.lastEventId || this.lastEventId;
        const payload = parseJsonSafely<unknown>(e.data);
        console.warn("[RoomTabletStore] Room alert", payload);
      });

      this.sse.addEventListener("stream.draining", () => {
        console.info("[RoomTabletStore] Server requested stream rotation");
        this.disconnectSSE();
        this.connectSSE(roomId);
      });

      this.sse.onerror = () => {
        this.sseConnected = false;
        this.reconnectAttempts += 1;
        console.warn("[RoomTabletStore] SSE connection dropped; native retry in progress", {
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
      if (this.roomId !== payload.room_id) return;

      const existingRoom = this.snapshot.room;
      const roomName = payload.room_name ?? existingRoom?.name ?? `Room ${payload.room_id}`;
      const room: Room = existingRoom
        ? { ...existingRoom, name: roomName }
        : { id: payload.room_id, name: roomName };

      const children = payload.children ?? [];

      this.snapshot = {
        room,
        current_count: payload.current_count ?? children.length,
        children,
      };
    },
  },
});

