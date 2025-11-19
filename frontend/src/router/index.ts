// src/router/index.ts
import { createRouter, createWebHistory } from 'vue-router'
import RoomsView from '../views/RoomsView.vue'

const routes = [
  { path: '/rooms', name: 'Rooms', component: RoomsView }
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes
})

export default router
