import { createRouter, createWebHashHistory } from "vue-router";

const DashboardView = () => import("../views/DashboardView.vue");
const AdminView = () => import("../views/AdminView.vue");
const ChildrenAdminView = () => import("../views/admin/ChildrenAdminView.vue");
const RoomsAdminView = () => import("../views/admin/RoomsAdminView.vue");
const DevicesAdminView = () => import("../views/admin/DevicesAdminView.vue");
const MovementAdminView = () => import("../views/admin/MovementAdminView.vue");

const routes = [
  { path: "/", redirect: "/dashboard" },
  {
    path: "/dashboard",
    name: "Dashboard",
    component: DashboardView,
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
