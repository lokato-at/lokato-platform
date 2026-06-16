import { createRouter, createWebHashHistory } from "vue-router";

const DashboardView = () => import("../views/DashboardView.vue");
const AdminView = () => import("../views/AdminView.vue");
const ChildrenAdminView = () => import("../views/admin/ChildrenAdminView.vue");
const RoomsAdminView = () => import("../views/admin/RoomsAdminView.vue");
const DevicesAdminView = () => import("../views/admin/DevicesAdminView.vue");
const MovementAdminView = () => import("../views/admin/MovementAdminView.vue");
const RoomTabletView = () => import("../views/tablet/RoomTabletView.vue");
const TVRoomView = () => import("../views/TVRoomView.vue");
const AllRoomsView = () => import("../views/AllRoomsView.vue");

const routes = [
  { path: "/", redirect: "/dashboard" },
  {
    path: "/dashboard",
    name: "Dashboard",
    component: DashboardView,
  },
  {
    path: "/tablet/:roomId",
    name: "RoomTablet",
    component: RoomTabletView,
  },
  {
    path: "/tv",
    name: "TVRoom",
    component: TVRoomView,
  },
  {
    path: "/rooms",
    name: "AllRooms",
    component: AllRoomsView,
  },
  {
    path: "/admin/home",
    name: "AdminHome",
    component: AdminView,
  },
  {
    path: "/admin/children",
    name: "AdminChildren",
    component: ChildrenAdminView,
  },
  {
    path: "/admin/rooms",
    name: "AdminRooms",
    component: RoomsAdminView,
  },
  {
    path: "/admin/devices",
    name: "AdminDevices",
    component: DevicesAdminView,
  },
  {
    path: "/admin/movements",
    name: "AdminMovements",
    component: MovementAdminView,
  },
  { path: "/:pathMatch(.*)*", redirect: "/dashboard" },
];

const router = createRouter({
  history: createWebHashHistory(import.meta.env.BASE_URL),
  routes,
});

export default router;
