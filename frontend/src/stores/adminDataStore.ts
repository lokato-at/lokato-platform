import { defineStore } from "pinia";
import api from "../api/axios";

export interface AdminRoom {
  id: number;
  name: string;
  area?: string | null;
  capacity?: number;
  tolerance?: number;
  current_count?: number;
  is_active?: boolean;
}

export interface AdminDevice {
  id: number;
  name: string;
  device_key?: string;
  room_id?: number | null;
  room?: AdminRoom | null;
}

export interface AdminChild {
  id: number;
  name: string;
  photo_url?: string | null;
  tracker_uid?: string | null;
  is_active?: boolean;
}

export interface AdminSummary {
  children_count: number;
  rooms_count: number;
  devices_count: number;
}

function sleep(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function isTimeoutError(error: unknown): boolean {
  return (
    typeof error === "object" &&
    error !== null &&
    "code" in error &&
    (error as { code?: string }).code === "ECONNABORTED"
  );
}

async function getWithRetry<T>(url: string, timeout: number, retries = 2): Promise<T> {
  let lastError: unknown;

  for (let attempt = 0; attempt <= retries; attempt += 1) {
    try {
      const response = await api.get(url, { timeout });
      return response.data as T;
    } catch (error) {
      lastError = error;

      if (!isTimeoutError(error) || attempt === retries) {
        throw error;
      }

      await sleep(750 * (attempt + 1));
    }
  }

  throw lastError;
}

export const useAdminDataStore = defineStore("adminDataStore", {
  state: () => ({
    children: [] as AdminChild[],
    rooms: [] as AdminRoom[],
    devices: [] as AdminDevice[],
    summary: {
      children_count: 0,
      rooms_count: 0,
      devices_count: 0,
    } as AdminSummary,

    lastScanResult: null as unknown,

    loading: false,
    error: null as string | null,
  }),

  actions: {
    setError(msg: string, err?: unknown) {
      this.error = msg;
      console.error("[AdminStore ERROR]", msg, err ?? "");
    },

    clearError() {
      this.error = null;
    },

    async loadAdminSummary() {
      this.clearError();

      try {
        this.summary = await getWithRetry<AdminSummary>("/admin/summary", 15000);
      } catch (err) {
        this.setError("Fehler beim Laden der Admin-Übersicht", err);
      }
    },

    async loadChildren() {
      this.clearError();

      try {
        this.children = await getWithRetry<AdminChild[]>("/admin/children", 15000);
        this.summary.children_count = this.children.length;
      } catch (err) {
        this.setError("Fehler beim Laden der Admin-Kinder", err);
      }
    },

    async loadRooms() {
      this.clearError();

      try {
        this.rooms = await getWithRetry<AdminRoom[]>("/admin/rooms", 15000);
        this.summary.rooms_count = this.rooms.length;
      } catch (err) {
        this.setError("Fehler beim Laden der Admin-Räume", err);
      }
    },

    async loadDevices() {
      this.clearError();

      try {
        this.devices = await getWithRetry<AdminDevice[]>("/admin/devices", 15000);
        this.summary.devices_count = this.devices.length;
      } catch (err) {
        this.setError("Fehler beim Laden der Admin-Geräte", err);
      }
    },

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

    async updateChild(id: number, payload: Partial<AdminChild>) {
      try {
        const clean: Partial<AdminChild> = {};

        if (typeof payload.name === "string" && payload.name.trim()) {
          clean.name = payload.name;
        }
        if (typeof payload.photo_url === "string" && payload.photo_url.trim()) {
          clean.photo_url = payload.photo_url;
        }
        if (typeof payload.tracker_uid === "string" && payload.tracker_uid.trim()) {
          clean.tracker_uid = payload.tracker_uid;
        }
        if (typeof payload.is_active === "boolean") {
          clean.is_active = payload.is_active;
        }

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

    async createRoom(payload: Pick<AdminRoom, "name"> & Partial<AdminRoom>) {
      try {
        await api.post("/admin/rooms", payload);
        await this.loadRooms();
      } catch (err) {
        this.setError("Fehler beim Erstellen eines Raums", err);
      }
    },

    async updateRoom(id: number, payload: Partial<AdminRoom>) {
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

    async createDevice(payload: Pick<AdminDevice, "name"> & Partial<AdminDevice>) {
      try {
        await api.post("/admin/devices", payload);
        await this.loadDevices();
      } catch (err) {
        this.setError("Fehler beim Erstellen eines Geräts", err);
      }
    },

    async updateDevice(id: number, payload: Partial<AdminDevice>) {
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

    async loadAllAdminData() {
      this.loading = true;
      this.error = null;

      try {
        await this.loadAdminSummary();
      } catch (err) {
        this.setError("Fehler beim Laden der Admin-Daten", err);
      } finally {
        this.loading = false;
      }
    },
  },
});
