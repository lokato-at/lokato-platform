import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueDevTools(),
  ],
  server: {
    port: 5173,
    proxy: {
      // Greift NUR, wenn du den Vite-Dev-Server direkt aufrufst
      // (http://localhost:5173). Bei `docker compose up` laeuft alles ueber
      // nginx auf http://localhost — dann uebernimmt nginx das /api-Routing
      // und dieser Proxy ist umgangen.
      // Backend-Port 8001 entspricht php artisan serve (legacy Host-Workflow).
      '/api': 'http://localhost:8001'
    }
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    },
  },
})
