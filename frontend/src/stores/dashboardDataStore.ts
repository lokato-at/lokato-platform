import { defineStore } from "pinia";
import api from "../api/axios";

export const useDashboardDataStore = defineStore("dashboardDataStore", {
  state: () => ({
    rooms: null as null | any[],
    occupancy: {} as Record<number, any>,
    latestMovements: [] as any[],
    loading: false as boolean,
    error: null as string | null,
  }),

  actions: {
    async fetchAllDashboardData() {
      this.loading = true;
      this.error = null;

      try {
        // 1) Räume holen
        const roomsRes = await api.get("/rooms");
        this.rooms = roomsRes.data;

        // 2) pro Raum die Occupancy holen
        const occMap: Record<number, any> = {};
        for (const room of roomsRes.data) {
          try {
            const occRes = await api.get(`/rooms/${room.id}/occupancy`);
            occMap[room.id] = occRes.data;
          } catch (innerErr) {
            console.warn(`Fehler beim Laden der Occupancy für Raum ${room.id}`, innerErr);
          }
        }
        this.occupancy = occMap;

        // 3) Movement-Log (letzte 5)
        const movRes = await api.get("/movement-log");
        this.latestMovements = movRes.data?.data?.slice(0, 5) || [];

      } catch (err) {
        this.error = err instanceof Error ? err.message : "Unknown error in Dashboard Store";
      } finally {
        this.loading = false;
      }
    }
  }
});
