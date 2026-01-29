import { createRouter, createWebHashHistory } from "vue-router";
import DashboardView from "../views/DashboardView.vue";
import AdminView from "../views/AdminView.vue";
import ChildrenAdminView from "../views/admin/ChildrenAdminView.vue";
import RoomsAdminView from "../views/admin/RoomsAdminView.vue";
import DevicesAdminView from "../views/admin/DevicesAdminView.vue";
import MovementAdminView from "../views/admin/MovementAdminView.vue";

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
