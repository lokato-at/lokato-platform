import { defineStore } from "pinia";
import api from "../api/axios";

export const useAdminDataStore = defineStore("adminDataStore", {
  state: () => ({
    children: [] as any[],
    rooms: [] as any[],
    devices: [] as any[],
    lastScanResult: null as any,

    loading: false,
    error: null as string | null,
  }),

  actions: {
    setError(msg: string, err?: any) {
      this.error = msg;
      console.error("[AdminStore ERROR]", msg, err ?? "");
    },

    /* -------------------------
       LOADERS
    ------------------------- */
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

    /* -------------------------
       CHILD CRUD (safe + reloads lazily)
    ------------------------- */
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

        // reload local list
        await this.loadChildren();

        // Try to reload other stores if they exist — do lazy dynamic import to avoid cycles.
        try {
          const dashMod = await import("./dashboardDataStore");
          const dash = dashMod.useDashboardDataStore();
          if (dash?.fetchAllDashboardData) await dash.fetchAllDashboardData();
        } catch (e) {
          // not fatal — log and continue
          console.warn("[AdminStore] Could not reload dashboard store:", e);
        }

        try {
          const devMod = await import("./devDataStore");
          const dev = devMod.useDevDataStore();
          if (dev?.fetchAll) await dev.fetchAll();
        } catch (e) {
          console.warn("[AdminStore] Could not reload dev store:", e);
        }
      } catch (err) {
        this.setError("Fehler beim Erstellen eines Kindes", err);
      }
    },

    async updateChild(id: number, payload: any) {
      try {
        const clean = {
          ...payload,
          photo_url: payload.photo_url || null,
          tracker_uid: payload.tracker_uid || null,
        };

        await api.patch(`/admin/children/${id}`, clean);

        await this.loadChildren();

        // lazy reloads
        try {
          const dashMod = await import("./dashboardDataStore");
          const dash = dashMod.useDashboardDataStore();
          if (dash?.fetchAllDashboardData) await dash.fetchAllDashboardData();
        } catch (e) {
          console.warn("[AdminStore] reload dashboard failed", e);
        }

        try {
          const devMod = await import("./devDataStore");
          const dev = devMod.useDevDataStore();
          if (dev?.fetchAll) await dev.fetchAll();
        } catch (e) {
          console.warn("[AdminStore] reload dev failed", e);
        }
      } catch (err) {
        this.setError("Fehler beim Aktualisieren eines Kindes", err);
      }
    },

    async deleteChild(id: number) {
      try {
        await api.delete(`/admin/children/${id}`);
        await this.loadChildren();

        // lazy reloads
        try {
          const dashMod = await import("./dashboardDataStore");
          const dash = dashMod.useDashboardDataStore();
          if (dash?.fetchAllDashboardData) await dash.fetchAllDashboardData();
        } catch (e) {
          console.warn("[AdminStore] reload dashboard failed", e);
        }

        try {
          const devMod = await import("./devDataStore");
          const dev = devMod.useDevDataStore();
          if (dev?.fetchAll) await dev.fetchAll();
        } catch (e) {
          console.warn("[AdminStore] reload dev failed", e);
        }
      } catch (err) {
        this.setError("Fehler beim Löschen eines Kindes", err);
      }
    },

    /* -------------------------
       ROOMS CRUD
    ------------------------- */
    async createRoom(payload: any) {
      try {
        await api.post("/admin/rooms", payload);
        await this.loadRooms();

        // try lazy reload
        try {
          const dashMod = await import("./dashboardDataStore");
          const dash = dashMod.useDashboardDataStore();
          if (dash?.fetchAllDashboardData) await dash.fetchAllDashboardData();
        } catch (e) { console.warn("[AdminStore] reload dashboard failed", e); }
      } catch (err) {
        this.setError("Fehler beim Erstellen eines Raums", err);
      }
    },

    async updateRoom(id: number, payload: any) {
      try {
        await api.patch(`/admin/rooms/${id}`, payload);
        await this.loadRooms();

        try {
          const dashMod = await import("./dashboardDataStore");
          const dash = dashMod.useDashboardDataStore();
          if (dash?.fetchAllDashboardData) await dash.fetchAllDashboardData();
        } catch (e) { console.warn("[AdminStore] reload dashboard failed", e); }
      } catch (err) {
        this.setError("Fehler beim Aktualisieren eines Raums", err);
      }
    },

    async deleteRoom(id: number) {
      try {
        await api.delete(`/admin/rooms/${id}`);
        await this.loadRooms();

        try {
          const dashMod = await import("./dashboardDataStore");
          const dash = dashMod.useDashboardDataStore();
          if (dash?.fetchAllDashboardData) await dash.fetchAllDashboardData();
        } catch (e) { console.warn("[AdminStore] reload dashboard failed", e); }
      } catch (err) {
        this.setError("Fehler beim Löschen eines Raums", err);
      }
    },

    /* -------------------------
       DEVICES CRUD
    ------------------------- */
    async createDevice(payload: any) {
      try {
        await api.post("/admin/devices", payload);
        await this.loadDevices();

        try {
          const dashMod = await import("./dashboardDataStore");
          const dash = dashMod.useDashboardDataStore();
          if (dash?.fetchAllDashboardData) await dash.fetchAllDashboardData();
        } catch (e) { console.warn("[AdminStore] reload dashboard failed", e); }
      } catch (err) {
        this.setError("Fehler beim Erstellen eines Geräts", err);
      }
    },

    async updateDevice(id: number, payload: any) {
      try {
        await api.patch(`/admin/devices/${id}`, payload);
        await this.loadDevices();

        try {
          const dashMod = await import("./dashboardDataStore");
          const dash = dashMod.useDashboardDataStore();
          if (dash?.fetchAllDashboardData) await dash.fetchAllDashboardData();
        } catch (e) { console.warn("[AdminStore] reload dashboard failed", e); }
      } catch (err) {
        this.setError("Fehler beim Aktualisieren eines Geräts", err);
      }
    },

    async deleteDevice(id: number) {
      try {
        await api.delete(`/admin/devices/${id}`);
        await this.loadDevices();

        try {
          const dashMod = await import("./dashboardDataStore");
          const dash = dashMod.useDashboardDataStore();
          if (dash?.fetchAllDashboardData) await dash.fetchAllDashboardData();
        } catch (e) { console.warn("[AdminStore] reload dashboard failed", e); }
      } catch (err) {
        this.setError("Fehler beim Löschen eines Geräts", err);
      }
    },

    /* -------------------------
       MOVEMENT SIMULATOR (POST /scan)
    ------------------------- */
    async sendScanEvent(payload: {
      device_key: string;
      tracker_uid: string;
      event_time?: string;
    }) {
      try {
        const res = await api.post("/scan", payload);
        this.lastScanResult = res.data;

        // best-effort: update dashboard and dev stores
        try {
          const dashMod = await import("./dashboardDataStore");
          const dash = dashMod.useDashboardDataStore();
          // fetch latest movements only to reduce load
          if (dash?.fetchLatestMovements) await dash.fetchLatestMovements(5);
        } catch (e) {
          console.warn("[AdminStore] updating dashboard after scan failed", e);
        }

        try {
          const devMod = await import("./devDataStore");
          const dev = devMod.useDevDataStore();
          if (dev?.fetchAll) await dev.fetchAll();
        } catch (e) {
          console.warn("[AdminStore] updating dev after scan failed", e);
        }

        return res.data;
      } catch (err) {
        this.setError("Fehler beim Simulieren eines Scan-Events", err);
        throw err;
      }
    },

    /* -------------------------
       LOAD EVERYTHING
    ------------------------- */
    async loadAllAdminData() {
      this.loading = true;
      this.error = null;

      try {
        await Promise.all([this.loadChildren(), this.loadRooms(), this.loadDevices()]);
      } catch (err) {
        this.setError("Fehler beim Laden der Admin-Daten", err);
      } finally {
        this.loading = false;
      }
    },
  },
});
