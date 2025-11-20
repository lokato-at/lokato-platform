import { createRouter, createWebHistory } from "vue-router";

/* ============================
   MAIN VIEWS
============================ */
import DashboardView from "../views/DashboardView.vue";
import DevView from "../views/DevView.vue";
import AdminView from "../views/AdminView.vue";

/* ============================
   ADMIN SUBVIEWS
============================ */
import ChildrenAdminView from "../views/admin/ChildrenAdminView.vue";
import RoomsAdminView from "../views/admin/RoomsAdminView.vue";
import DevicesAdminView from "../views/admin/DevicesAdminView.vue";
import MovementAdminView from "../views/admin/MovementAdminView.vue";

/* ============================
   TEST SUITE
============================ */
import APITestView from "../views/APITestView.vue";

const routes = [
  /* Redirect Root → Dashboard */
  { path: "/", redirect: "/dashboard" },

  /* ============================
     PUBLIC DASHBOARD
  ============================ */
  {
    path: "/dashboard",
    name: "Dashboard",
    component: DashboardView,
  },

  /* ============================
     ADMIN BEREICH
     (Hauptseite + Unterseiten)
  ============================ */
  {
    path: "/admin",
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

  /* ============================
     DEV-ROHDATEN
  ============================ */
  {
    path: "/dev",
    name: "Dev",
    component: DevView,
  },

  /* ============================
     API TEST SUITE
     (manuelle UI zum Testen)
  ============================ */
  {
    path: "/tests",
    name: "APITests",
    component: APITestView,
  },

  /* ============================
     FALLBACK → Dashboard
  ============================ */
  { path: "/:pathMatch(.*)*", redirect: "/dashboard" },
];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
});

export default router;
