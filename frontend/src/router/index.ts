import { createRouter, createWebHashHistory } from 'vue-router'
import type { RouteLocationNormalized } from 'vue-router'
import { useAuthStore } from '@/stores/authStore'

const DashboardView = () => import('../views/DashboardView.vue')
const AdminView = () => import('../views/AdminView.vue')
const ChildrenAdminView = () => import('../views/admin/ChildrenAdminView.vue')
const RoomsAdminView = () => import('../views/admin/RoomsAdminView.vue')
const DevicesAdminView = () => import('../views/admin/DevicesAdminView.vue')
const MovementAdminView = () => import('../views/admin/MovementAdminView.vue')
const RoomTabletView = () => import('../views/tablet/RoomTabletView.vue')
const TVRoomView = () => import('../views/TVRoomView.vue')
const AllRoomsView = () => import('../views/AllRoomsView.vue')
const LoginView = () => import('../views/LoginView.vue')

const routes = [
  { path: '/', redirect: '/dashboard' },
  { path: '/login', name: 'Login', component: LoginView, meta: { public: true } },
  {
    path: '/dashboard',
    name: 'Dashboard',
    component: DashboardView,
    meta: { public: true },
  },
  {
    // Path-Parameter variant: /tablet/3
    path: '/tablet/:roomId',
    name: 'RoomTablet',
    component: RoomTabletView,
    meta: { public: true },
  },
  {
    // Query-Parameter variant: /tablet?id=3 or /tablet/?id=3
    path: '/tablet',
    name: 'RoomTabletByQuery',
    component: RoomTabletView,
    meta: { public: true },
  },
  {
    path: '/all-rooms',
    name: 'AllRoomsView',
    component: AllRoomsView,
    meta: { public: true },
  },
  {
    path: '/tv',
    name: 'TVRoomView',
    component: TVRoomView,
    meta: { public: true },
  },
  {
    path: '/admin/home',
    name: 'AdminHome',
    component: AdminView,
    meta: { requiresAuth: true },
  },
  {
    path: '/admin/children',
    name: 'AdminChildren',
    component: ChildrenAdminView,
    meta: { requiresAuth: true },
  },
  {
    path: '/admin/rooms',
    name: 'AdminRooms',
    component: RoomsAdminView,
    meta: { requiresAuth: true },
  },
  {
    path: '/admin/devices',
    name: 'AdminDevices',
    component: DevicesAdminView,
    meta: { requiresAuth: true },
  },
  {
    path: '/admin/movements',
    name: 'AdminMovements',
    component: MovementAdminView,
    meta: { requiresAuth: true },
  },
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]

const router = createRouter({
  history: createWebHashHistory(import.meta.env.BASE_URL),
  routes,
})

router.beforeEach((to: RouteLocationNormalized) => {
  if (!to.meta?.requiresAuth) return true

  const auth = useAuthStore()
  if (auth.isAuthenticated) return true

  return {
    path: '/login',
    query: { redirect: to.fullPath },
  }
})

export default router
