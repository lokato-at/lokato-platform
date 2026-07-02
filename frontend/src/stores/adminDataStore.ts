import type { AxiosError, AxiosRequestConfig } from "axios";
import { defineStore } from "pinia";
import api from "../api/axios";
import { buildApiUrl } from "@/utils/api";

const ADMIN_REQUEST_TIMEOUT_MS = 30000;
const ADMIN_TIMEOUT_RETRY_ATTEMPTS = 2;
const ADMIN_TIMEOUT_RETRY_DELAY_MS = 1500;
// Throttle gegen Scan-Bursts: sonst loest jeder Scan einen vollen List-Reload aus.
const ADMIN_SSE_REFRESH_THROTTLE_MS = 1000;

export interface AdminRoom {
  id: number;
  name: string;
  area?: string | null;
  icon?: string | null;
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

// Anlern-Modus: eine gescannte, noch keinem Kind zugewiesene Tracker-UID.
export interface TrackerSighting {
  tracker_uid: string;
  device_id?: number | null;
  device_name?: string | null;
  room_id?: number | null;
  room_name?: string | null;
  last_seen_at?: string | null;
}

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

// Laravel-422 liefert { message, errors: { feld: [msg] } }. Fuer Konflikte wie
// "Tracker gehört bereits zu Kind X" wollen wir die feld-spezifische Meldung
// statt eines generischen Fallbacks anzeigen.
const extractApiMessage = (err: unknown, fallback: string): string => {
  const axiosErr = err as
    | AxiosError<{ message?: string; errors?: Record<string, string[]> }>
    | undefined;
  const data = axiosErr?.response?.data;
  const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
  return firstFieldError ?? data?.message ?? fallback;
};

const isTimeoutError = (err: unknown) => {
  const axiosError = err as AxiosError | undefined;

  return axiosError?.code === "ECONNABORTED" || axiosError?.message?.includes("timeout");
};

export const useAdminDataStore = defineStore("adminDataStore", {
  state: () => ({
    children: [] as AdminChild[],
    rooms: [] as AdminRoom[],
    devices: [] as AdminDevice[],
    trackerSightings: [] as TrackerSighting[],
    summary: {
      children_count: 0,
      rooms_count: 0,
      devices_count: 0,
    } as AdminSummary,

    lastScanResult: null as unknown,

    loading: false,
    error: null as string | null,

    sse: null as EventSource | null,
    sseConnected: false,
    sseLastEventId: null as string | null,
    sseLastChildRefreshAt: 0,
    sseLastRoomRefreshAt: 0,
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
    }): Promise<AdminChild | null> {
      try {
        const clean = {
          name: payload.name,
          photo_url: payload.photo_url || null,
          tracker_uid: payload.tracker_uid || null,
          is_active: payload.is_active ?? true,
        };

        const res = await api.post<AdminChild>("/admin/children", clean);
        await this.loadChildren();
        return res.data;
      } catch (err) {
        this.setError(extractApiMessage(err, "Fehler beim Erstellen eines Kindes"), err);
        return null;
      }
    },

    async uploadChildPhoto(childId: number, file: File) {
      try {
        const formData = new FormData();
        formData.append("photo", file);
        await api.post(`/admin/children/${childId}/photo`, formData, {
          headers: { "Content-Type": "multipart/form-data" },
        });
        await this.loadChildren();
      } catch (err) {
        this.setError("Fehler beim Hochladen des Fotos", err);
        throw err;
      }
    },

    async deleteChildPhoto(childId: number) {
      try {
        await api.delete(`/admin/children/${childId}/photo`);
        await this.loadChildren();
      } catch (err) {
        this.setError("Fehler beim Entfernen des Fotos", err);
        throw err;
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
        this.setError(extractApiMessage(err, "Fehler beim Aktualisieren eines Kindes"), err);
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

    async loadTrackerSightings() {
      try {
        const res = await api.get<TrackerSighting[]>("/admin/tracker-sightings", {
          timeout: ADMIN_REQUEST_TIMEOUT_MS,
        });
        this.trackerSightings = res.data as TrackerSighting[];
      } catch (err) {
        // Poll-Fehler bewusst NICHT als harten Store-Error zeigen — sonst
        // blinkt im Anlern-Modus bei jedem transienten Timeout eine Meldung.
        console.warn("[AdminStore] tracker-sightings poll failed", err);
      }
    },

    async dismissTrackerSighting(trackerUid: string) {
      try {
        await api.delete(`/admin/tracker-sightings/${encodeURIComponent(trackerUid)}`);
        this.trackerSightings = this.trackerSightings.filter(
          (sighting) => sighting.tracker_uid !== trackerUid,
        );
      } catch (err) {
        this.setError("Sichtung konnte nicht verworfen werden", err);
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

    connectSSE() {
      if (this.sse) return;

      const params = new URLSearchParams();
      if (this.sseLastEventId) params.set("last_event_id", this.sseLastEventId);
      const qs = params.toString();
      this.sse = new EventSource(buildApiUrl(qs ? `/stream?${qs}` : "/stream"));

      this.sse.onopen = () => {
        this.sseConnected = true;
      };

      // child.moved kann is_active von false→true flippen (erster Scan).
      this.sse.addEventListener("child.moved", (e: MessageEvent) => {
        this.sseLastEventId = e.lastEventId || this.sseLastEventId;
        const now = Date.now();
        if (now - this.sseLastChildRefreshAt < ADMIN_SSE_REFRESH_THROTTLE_MS) return;
        this.sseLastChildRefreshAt = now;
        void this.loadChildren();
      });

      this.sse.addEventListener("room.status.updated", (e: MessageEvent) => {
        this.sseLastEventId = e.lastEventId || this.sseLastEventId;
        const now = Date.now();
        if (now - this.sseLastRoomRefreshAt < ADMIN_SSE_REFRESH_THROTTLE_MS) return;
        this.sseLastRoomRefreshAt = now;
        void this.loadRooms();
      });

      this.sse.addEventListener("stream.draining", () => {
        this.disconnectSSE();
        this.connectSSE();
      });

      this.sse.onerror = () => {
        this.sseConnected = false;
      };
    },

    disconnectSSE() {
      if (this.sse) this.sse.close();
      this.sse = null;
      this.sseConnected = false;
    },
  },
});
