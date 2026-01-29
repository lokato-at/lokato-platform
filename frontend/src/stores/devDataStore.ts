import { defineStore } from "pinia";
import api from "../api/axios";

/*
|--------------------------------------------------------------------------
| DevDataStore
|--------------------------------------------------------------------------
| Dieser Store dient ausschließlich zu **Test- und Entwicklungszwecken**.
|
| Ziel:
|   - Alle Public-Endpunkte laden
|   - Alle Admin-Endpunkte laden
|   - Paginiertes Movement Log testen
|   - Beispiel-IDs abrufen (Child #1, Room #1, Device #1)
|
| NICHT für das Live-Dashboard gedacht – nur Dev-Werkzeug!
*/
export const useDevDataStore = defineStore("devDataStore", {
  state: () => ({

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


    movement: {
      all: null as unknown,
      byChild: null as unknown,
    },

    admin: {
      children: null as null | unknown[],
      child: null as unknown,
      rooms: null as null | unknown[],
      room: null as unknown,
      devices: null as null | unknown[],
      device: null as unknown,
    },


    loading: false as boolean,
    error: null as string | null,
  }),

  actions: {
    /*
    |--------------------------------------------------------------------------
    | fetchAll()
    |--------------------------------------------------------------------------
    |
    |   - Lädt ALLE Public Daten
    |   - Lädt ALLE Admin Daten
    |   - Ruft Beispiel-Endpunkte für ID=1 ab:
    |       /children/1
    |       /rooms/1/occupancy
    |       /children/1/movement-log
    |       /admin/children/1
    |       /admin/rooms/1
    |       /admin/devices/1
    |
    | fetchAll() wird im DevView verwendet.
    */
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


          api.get("/children/1"),
          api.get("/rooms/1/occupancy"),
          api.get("/children/1/movement-log"),


          api.get("/admin/children/1"),
          api.get("/admin/rooms/1"),
          api.get("/admin/devices/1"),
        ]);


        this.public.children = childrenRes.data;
        this.public.child = child1Res.data;
        this.public.rooms = roomsRes.data;
        this.public.roomOccupancy = room1OccRes.data;


        this.movement.all = movementRes.data;
        this.movement.byChild = child1MovRes.data;


        this.admin.children = adminChildrenRes.data;
        this.admin.child = adminChild1Res.data;
        this.admin.rooms = adminRoomsRes.data;
        this.admin.room = adminRoom1Res.data;
        this.admin.devices = adminDevicesRes.data;
        this.admin.device = adminDevice1Res.data;

      } catch (err) {

        this.error =
          err instanceof Error ? err.message : "Unknown error during /dev fetch";
      } finally {
        this.loading = false;
      }
    },
  },
});
