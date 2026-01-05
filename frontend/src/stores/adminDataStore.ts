import { defineStore } from "pinia";
import api from "../api/axios";

/*
|--------------------------------------------------------------------------
| Admin Data Store (SSE-SAFE)
|--------------------------------------------------------------------------
| Verwaltet ausschließlich Admin-relevante CRUD-Daten:
| - Kinder
| - Räume
| - Geräte
| - Movement-Test-Events
|
| WICHTIGE REGEL:
| - Dieser Store kennt KEIN Dashboard
| - KEINE SSE-Logik
| - KEINE Dashboard-Reloads
| - Admin = Command, Dashboard = Observe
*/
export const useAdminDataStore = defineStore("adminDataStore", {
  /* =====================================================================
     STATE
     ===================================================================== */
  state: () => ({
    children: [] as unknown[],
    rooms: [] as unknown[],
    devices: [] as unknown[],

    // Ergebnis des Movement-Simulators (/scan)
    lastScanResult: null as unknown,

    loading: false,
    error: null as string | null,
  }),

  /* =====================================================================
     ACTIONS
     ===================================================================== */
  actions: {
    /* -------------------------------------------------------------------
       ERROR HANDLER
    ------------------------------------------------------------------- */
    setError(msg: string, err?: unknown) {
      this.error = msg;
      console.error("[AdminStore ERROR]", msg, err ?? "");
    },

    /* =====================================================================
       LOADERS
       ===================================================================== */

    async loadChildren() {
      try {
        const res = await api.get("/admin/children");
        this.children = res.data;
      } catch (err) {
        this.setError("Fehler beim Laden der Admin-Kinder", err);
      }
    },

    async loadRooms() {
      try {
        const res = await api.get("/admin/rooms");
        this.rooms = res.data;
      } catch (err) {
        this.setError("Fehler beim Laden der Admin-Räume", err);
      }
    },

    async loadDevices() {
      try {
        const res = await api.get("/admin/devices");
        this.devices = res.data;
      } catch (err) {
        this.setError("Fehler beim Laden der Admin-Geräte", err);
      }
    },

    /* =====================================================================
       CHILD CRUD
       ===================================================================== */

    async createChild(payload: {
      name: string;
      photo_url: string | null;
      tracker_uid: string | null;
      is_active: boolean;
    }) {
      try {
        const clean = {
          name: payload.name,
          photo_url: payload.photo_url || null,
          tracker_uid: payload.tracker_uid || null,
          is_active: payload.is_active ?? true,
        };

        await api.post("/admin/children", clean);
        await this.loadChildren();
      } catch (err) {
        this.setError("Fehler beim Erstellen eines Kindes", err);
      }
    },

    async updateChild(id: number, payload: unknown) {
      try {
        const clean: unknown = {};

        if (payload.name?.trim()) clean.name = payload.name;
        if (payload.photo_url?.trim()) clean.photo_url = payload.photo_url;
        if (payload.tracker_uid?.trim()) clean.tracker_uid = payload.tracker_uid;
        if (typeof payload.is_active === "boolean") clean.is_active = payload.is_active;

        await api.patch(`/admin/children/${id}`, clean);
        await this.loadChildren();
      } catch (err) {
        this.setError("Fehler beim Aktualisieren eines Kindes", err);
      }
    },

    async deleteChild(id: number) {
      try {
        await api.delete(`/admin/children/${id}`);
        await this.loadChildren();
      } catch (err) {
        this.setError("Fehler beim Löschen eines Kindes", err);
      }
    },

    /* =====================================================================
       ROOMS CRUD
       ===================================================================== */

    async createRoom(payload: unknown) {
      try {
        await api.post("/admin/rooms", payload);
        await this.loadRooms();
      } catch (err) {
        this.setError("Fehler beim Erstellen eines Raums", err);
      }
    },

    async updateRoom(id: number, payload: unknown) {
      try {
        await api.patch(`/admin/rooms/${id}`, payload);
        await this.loadRooms();
      } catch (err) {
        this.setError("Fehler beim Aktualisieren eines Raums", err);
      }
    },

    async deleteRoom(id: number) {
      try {
        await api.delete(`/admin/rooms/${id}`);
        await this.loadRooms();
      } catch (err) {
        this.setError("Fehler beim Löschen eines Raums", err);
      }
    },

    /* =====================================================================
       DEVICES CRUD
       ===================================================================== */

    async createDevice(payload: unknown) {
      try {
        await api.post("/admin/devices", payload);
        await this.loadDevices();
      } catch (err) {
        this.setError("Fehler beim Erstellen eines Geräts", err);
      }
    },

    async updateDevice(id: number, payload: unknown) {
      try {
        await api.patch(`/admin/devices/${id}`, payload);
        await this.loadDevices();
      } catch (err) {
        this.setError("Fehler beim Aktualisieren eines Geräts", err);
      }
    },

    async deleteDevice(id: number) {
      try {
        await api.delete(`/admin/devices/${id}`);
        await this.loadDevices();
      } catch (err) {
        this.setError("Fehler beim Löschen eines Geräts", err);
      }
    },

    /* =====================================================================
       MOVEMENT SIMULATION (/scan)
       ===================================================================== */

    /*
     * Simuliert ein Movement.
     * Dashboard aktualisiert sich automatisch über SSE.
     */
    async sendScanEvent(payload: {
      device_key: string;
      tracker_uid: string;
      event_time?: string;
    }) {
      try {
        const res = await api.post("/scan", payload);
        this.lastScanResult = res.data;
        return res.data;
      } catch (err) {
        this.setError("Fehler beim Simulieren eines Scan-Events", err);
        throw err;
      }
    },

    /* =====================================================================
       LOAD EVERYTHING (Admin Overview)
       ===================================================================== */

    async loadAllAdminData() {
      this.loading = true;
      this.error = null;

      try {
        await Promise.all([
          this.loadChildren(),
          this.loadRooms(),
          this.loadDevices(),
        ]);
      } catch (err) {
        this.setError("Fehler beim Laden der Admin-Daten", err);
      } finally {
        this.loading = false;
      }
    },
  },
});
