import type { AxiosError, AxiosRequestConfig } from "axios";
import { defineStore } from "pinia";
import api from "../api/axios";

const ADMIN_REQUEST_TIMEOUT_MS = 30000;
const ADMIN_TIMEOUT_RETRY_ATTEMPTS = 2;
const ADMIN_TIMEOUT_RETRY_DELAY_MS = 1500;

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

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

const isTimeoutError = (err: unknown) => {
  const axiosError = err as AxiosError | undefined;

  return axiosError?.code === "ECONNABORTED" || axiosError?.message?.includes("timeout");
};

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

    async getWithTimeoutRetry<T>(
      url: string,
      config?: AxiosRequestConfig,
      retries = ADMIN_TIMEOUT_RETRY_ATTEMPTS,
    ) {
      let attempt = 0;

      while (true) {
        try {
          return await api.get<T>(url, {
            timeout: ADMIN_REQUEST_TIMEOUT_MS,
            ...config,
          });
        } catch (err) {
          if (!isTimeoutError(err) || attempt >= retries) {
            throw err;
          }

          attempt += 1;
          console.warn(
            `[AdminStore] Timeout loading ${url}. Retry ${attempt}/${retries} in ${ADMIN_TIMEOUT_RETRY_DELAY_MS}ms.`,
            err,
          );
          await sleep(ADMIN_TIMEOUT_RETRY_DELAY_MS * attempt);
        }
      }
    },

    async loadAdminSummary() {
      this.clearError();

      try {
        const res = await this.getWithTimeoutRetry<AdminSummary>("/admin/summary");
        this.summary = res.data as AdminSummary;
      } catch (err) {
        this.setError("Fehler beim Laden der Admin-Übersicht", err);
      }
    },

    async loadChildren() {
      this.clearError();

      try {
        const res = await this.getWithTimeoutRetry<AdminChild[]>("/admin/children");
        this.children = res.data as AdminChild[];
        this.summary.children_count = this.children.length;
      } catch (err) {
        this.setError("Fehler beim Laden der Admin-Kinder", err);
      }
    },

    async loadRooms() {
      this.clearError();

      try {
        const res = await this.getWithTimeoutRetry<AdminRoom[]>("/admin/rooms");
        this.rooms = res.data as AdminRoom[];
        this.summary.rooms_count = this.rooms.length;
      } catch (err) {
        this.setError("Fehler beim Laden der Admin-Räume", err);
      }
    },

    async loadDevices() {
      this.clearError();

      try {
        const res = await this.getWithTimeoutRetry<AdminDevice[]>("/admin/devices");
        this.devices = res.data as AdminDevice[];
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
