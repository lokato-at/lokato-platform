import { defineStore } from "pinia";
import api from "../api/axios";

export const useDevDataStore = defineStore("devDataStore", {
  state: () => ({
    /* ----------------------------------------------------------
       PUBLIC API – Children / Rooms
    ---------------------------------------------------------- */
    public: {
      children: null as null | Array<{
        id: number;
        name: string;
        photo_url: string | null;
        tracker_uid: string | null;
        is_active: boolean;
        location: string | null;
      }>,

      child: null as null | {
        id: number;
        name: string;
        photo_url: string | null;
        tracker_uid: string | null;
        is_active: boolean;
        location: string | null;
      },

      rooms: null as null | Array<{
        id: number;
        name: string;
        area: string | null;
        capacity: number;
        tolerance: number;
        is_active: boolean;
        current_count: number;
        status: {
          over_capacity: boolean;
          within_tolerance: boolean;
        };
      }>,

      roomOccupancy: null as null | {
        room: {
          id: number;
          name: string;
          area: string | null;
          capacity: number;
          tolerance: number;
        };
        current_count: number;
        children: Array<{
          id: number;
          name: string;
          photo_url: string | null;
          tracker_uid: string | null;
          is_active: boolean;
          location: string | null;
        }>;
      },
    },

    /* ----------------------------------------------------------
       MOVEMENT LOG – Paginated
    ---------------------------------------------------------- */
    movement: {
      all: null as any,
      byChild: null as any,
    },

    /* ----------------------------------------------------------
       ADMIN API – Children / Rooms / Devices
    ---------------------------------------------------------- */
    admin: {
      children: null as null | any[],
      child: null as any,
      rooms: null as null | any[],
      room: null as any,
      devices: null as null | any[],
      device: null as any,
    },

    loading: false as boolean,
    error: null as string | null,
  }),

  actions: {
    /* ----------------------------------------------------------
       LADEN ALLER ENDPOINTS (inkl. Beispiel-IDs)
    ---------------------------------------------------------- */
    async fetchAll() {
      this.loading = true;
      this.error = null;

      try {
        const [
          childrenRes,
          roomsRes,
          movementRes,
          adminChildrenRes,
          adminRoomsRes,
          adminDevicesRes,

          // Detail-Endpoints für Beispiele
          child1Res,
          room1OccRes,
          child1MovRes,
          adminChild1Res,
          adminRoom1Res,
          adminDevice1Res,
        ] = await Promise.all([
          api.get("/children"),
          api.get("/rooms"),
          api.get("/movement-log"),
          api.get("/admin/children"),
          api.get("/admin/rooms"),
          api.get("/admin/devices"),

          /* Beispiel-IDs */
          api.get("/children/1"),
          api.get("/rooms/1/occupancy"),
          api.get("/children/1/movement-log"),
          api.get("/admin/children/1"),
          api.get("/admin/rooms/1"),
          api.get("/admin/devices/1"),
        ]);

        /* Public */
        this.public.children = childrenRes.data;
        this.public.child = child1Res.data;
        this.public.rooms = roomsRes.data;
        this.public.roomOccupancy = room1OccRes.data;

        /* Movement */
        this.movement.all = movementRes.data;
        this.movement.byChild = child1MovRes.data;

        /* Admin */
        this.admin.children = adminChildrenRes.data;
        this.admin.child = adminChild1Res.data;
        this.admin.rooms = adminRoomsRes.data;
        this.admin.room = adminRoom1Res.data;
        this.admin.devices = adminDevicesRes.data;
        this.admin.device = adminDevice1Res.data;

      } catch (err) {
        this.error = err instanceof Error ? err.message : "Unknown error";
      } finally {
        this.loading = false;
      }
    },
  },
});
