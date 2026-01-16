import { createRouter, createWebHashHistory } from "vue-router";

/*
|--------------------------------------------------------------------------
| Haupt-Views (Top-Level)
|--------------------------------------------------------------------------
| Diese Views sind die "großen" Seiten der App:
| - DashboardView  -> öffentliche Live-Ansicht (Benutzer-Frontend)
| - DevView        -> Entwicklertools / Rohdaten-Ansicht
| - AdminView      -> Übersichtsseite für den Adminbereich (Sub-Nav zeigt Unterseiten)
|
| Jede dieser Komponenten muss in src/views/ als .vue-Datei existieren.
*/
import DashboardView from "../views/DashboardView.vue";
import AdminView from "../views/AdminView.vue";

/*
|--------------------------------------------------------------------------
| Admin-Unterseiten
|--------------------------------------------------------------------------
| Diese Views sind einzelne Seiten unter dem Admin-Bereich.
| Der Benutzer (Admin) navigiert von AdminView in diese Unterseiten.
|
| Beispiele:
| - /admin/children  -> CRUD für Kinder
| - /admin/rooms     -> CRUD für Räume
| - /admin/devices   -> CRUD für Geräte
| - /admin/movements -> Movement-Test-Tool (Scan Simulator)
*/

//WICHTIG: Man muss sich die Vue Seite vorher anlegen und hier importieren bevor man eine Route erstellen kann

import ChildrenAdminView from "../views/admin/ChildrenAdminView.vue";
import RoomsAdminView from "../views/admin/RoomsAdminView.vue";
import DevicesAdminView from "../views/admin/DevicesAdminView.vue";
import MovementAdminView from "../views/admin/MovementAdminView.vue";


/*
|--------------------------------------------------------------------------
| Test-Suite (optional)
|--------------------------------------------------------------------------
| Kleine UI zum Ausführen automatischer API-Tests oder manueller Checks.
| Wird z.B. verwendet, um alle Endpunkte automatisiert zu prüfen.
*/


/*
|--------------------------------------------------------------------------
| ROUTE DEFINITION
|--------------------------------------------------------------------------
| Jede Route ist ein Objekt mit:
| - path: URL Pfad
| - name: optionaler Name (praktisch für programmatische Navigation)
| - component: component, das gerendert wird, wenn die Route aktiv ist
|
| Hinweis für die Frontend-HTML/CSS-Kollegin:
| - Die Komponenten sind eigenständige Seiten (.vue). Für konsistente UI
|   sollten wir im Layout (z. B. App.vue) Header / Footer / Sidebar
|   einheitlich implementieren. Die Router-View tauscht nur den Inhalt.
*/
const routes = [
  /* ------------------------------------------------------------------
     Root-Redirect
     - Wenn jemand '/' aufruft, schicken wir auf /dashboard.
     - Vorteil: klarer Einstiegspunkt in die App.
  ------------------------------------------------------------------ */
  { path: "/", redirect: "/dashboard" },

  /* ------------------------------------------------------------------
     PUBLIC DASHBOARD
     - Öffentliche Live-Ansicht.
     - Erwartet: Darstellung aller Räume, Kinder, letzte Bewegungen etc.
     - URL: /dashboard
  ------------------------------------------------------------------ */
  {
    path: "/dashboard",
    name: "Dashboard",
    component: DashboardView,
  },

  /* ------------------------------------------------------------------
     ADMIN HAUPTSEITE
     - AdminView ist die Übersichts-/Landing-Page im Adminbereich.
     - Von hier aus navigiert man zu den spezifizierten Unterseiten.
     - URL: /admin
     - Hinweis: AdminView kann (und sollte) Links/Buttons zu /admin/children, /admin/rooms etc. enthalten.
  ------------------------------------------------------------------ */
  {
    path: "/admin/home",
    name: "AdminHome",
    component: AdminView,
  },

  /* ------------------------------------------------------------------
     ADMIN UNTERSEITEN (CRUD & TOOLS)
     - Jede Route ist eine separate Seite für Bearbeitung / Listen / Formulare.
     - Für das Styling: Tabellen, Formulare und modale Dialoge sind hier die wichtigsten UI-Elemente.
  ------------------------------------------------------------------ */

  // Kinder
  {
    path: "/admin/children",
    name: "AdminChildren",
    component: ChildrenAdminView,
  },

  // Räume
  {
    path: "/admin/rooms",
    name: "AdminRooms",
    component: RoomsAdminView,
  },

  // Geräte
  {
    path: "/admin/devices",
    name: "AdminDevices",
    component: DevicesAdminView,
  },

  // Movements (Scan Simulator)
  {
    path: "/admin/movements",
    name: "AdminMovements",
    component: MovementAdminView,
  },



  /* ------------------------------------------------------------------
     FALLBACK (404-handling)
     - Alle nicht definierten Routen landen beim Dashboard.
     - Alternative wäre: eine eigene 404-View rendern.
  ------------------------------------------------------------------ */
  { path: "/:pathMatch(.*)*", redirect: "/dashboard" },
];


const router = createRouter({
  history: createWebHashHistory(import.meta.env.BASE_URL),
  routes,
});

export default router;
