<template>
  <div id="app">

    <!-- ⭐ Dark Header -->
    <header class="header">
      <h1>Lokato Plattform</h1>

      <!-- ⭐ Globale Uhr -->
      <div class="clock">{{ time }}</div>
    </header>

    <!-- ⭐ Neue moderne Navbar (Tabs, gleiche Breite) -->
    <nav class="nav">
      <router-link
        to="/dashboard"
        class="nav-item"
        :class="{ active: $route.path.startsWith('/dashboard') }"
      >
        Dashboard
      </router-link>

      <router-link
        to="/admin"
        class="nav-item"
        :class="{ active: $route.path.startsWith('/admin') }"
      >
        Admin
      </router-link>

      <router-link
        to="/dev"
        class="nav-item"
        :class="{ active: $route.path.startsWith('/dev') }"
      >
        Dev
      </router-link>
    </nav>

    <hr />

    <router-view />
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from "vue";

/* ==========================================================
   🔥 Globale Uhrzeit (läuft jede Sekunde)
   ========================================================== */
const time = ref("");

function updateClock() {
  const now = new Date();
  time.value = now.toLocaleTimeString("de-DE", {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  });
}

onMounted(() => {
  updateClock();
  const interval = setInterval(updateClock, 1000);
  onUnmounted(() => clearInterval(interval));
});
</script>

<style scoped>
/* ==========================================
   DARK HEADER
========================================== */
.header {
  position: relative;           /* Damit die Uhr rechts angeordnet werden kann */
  background: #1e1e2f;
  color: white;
  padding: 25px 0;
  margin-bottom: 30px;
  border-radius: 0 0 16px 16px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.25);
}

.header h1 {
  margin: 0;
  font-size: 2rem;
  letter-spacing: 1px;
}

/* ⭐ Die Uhr rechts oben */
.clock {
  position: absolute;
  right: 20px;
  top: 50%;
  transform: translateY(-50%);
  font-family: "Courier New", monospace;
  font-size: 1rem;
  opacity: 0.85;
}

/* ==========================================
   NAVBAR — Equal Width Buttons
========================================== */
.nav {
  display: flex;
  justify-content: center;
  gap: 10px;
  margin-bottom: 20px;

  background: #f3f3f7;
  padding: 8px;
  border-radius: 40px;
  width: fit-content;
  margin-left: auto;
  margin-right: auto;

  box-shadow: 0 2px 10px rgba(0,0,0,0.08);
}

.nav-item {
  flex: 1;
  min-width: 120px;
  text-align: center;

  padding: 10px 18px;
  border-radius: 30px;
  font-weight: 600;
  text-decoration: none;

  color: #333;
  background: transparent;
  transition: all 0.25s ease;
}

.nav-item:hover {
  background: rgba(0,0,0,0.08);
}

.nav-item.active {
  background: #3d5afe;
  color: white;
  box-shadow: 0 3px 8px rgba(61,90,254,0.4);
}

hr {
  margin: 25px 0;
  opacity: 0.4;
}

#app {
  max-width: 900px;
  margin: auto;
  font-family: sans-serif;
  text-align: center;
}
</style>
