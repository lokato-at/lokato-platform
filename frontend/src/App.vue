<template>
  <div id="app">
    <header class="header">
      <h1>Lokato</h1>
    </header>

    <img src="./views/images/hort_pregarten.svg" alt="Hort Pregarten" class="header-image" />

    <nav class="nav">
      <router-link
        to="/dashboard"
        class="nav-item"
        :class="{ active: route.path.startsWith('/dashboard') }"
      >
        Dashboard
      </router-link>

      <router-link
        to="/admin/home"
        class="nav-item"
        :class="{ active: route.path.startsWith('/admin/home') }"
      >
        Admin
      </router-link>
    </nav>

    <hr />
    <router-view />
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from "vue";
import { useRoute } from "vue-router";

const route = useRoute();
const time = ref("");
let intervalId: ReturnType<typeof setInterval> | null = null;

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
  intervalId = setInterval(updateClock, 1000);
});

onUnmounted(() => {
  if (intervalId) clearInterval(intervalId);
});
</script>

<style scoped>



h2 {
  font-size: 36px;
  font-family: Nunito, sans-serif;
}


h3 {
  font-size: 24px;
  font-family: Nunito, sans-serif;
}



.header {
  height: 55px;
  width: 100vw;
  position: relative;
  left: 50%;
  right: 50%;
  margin-left: -50vw;
  margin-right: -50vw;
  background: white;
  color: black;
  padding: 25px 0;
  box-sizing: border-box;
  margin-bottom: 0;
  border-radius: 0 0 16px 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10;
  position: relative;
}

.header h1 {
  font-size: 30px;
  font-family: Nunito, sans-serif;
  margin: 0;
  letter-spacing: 1px;
}



.header-image {
  width: 100vw;
  height: 281px;
  display: block;
  margin-left: -50vw;
  position: relative;
  left: 50%;
  margin-top: -30px;
  margin-bottom: 20px;
  z-index: 1;
  object-fit: cover;
}

.nav {
  display: flex;
  justify-content: center;
  gap: 15px;
  margin-bottom: 20px;
  margin-top: 20px;
  position: relative;
  z-index: 5;
}

.nav-item {
  height: 69px;
  width: 282px;
  padding: 10px 24px;
  border-radius: 15px;
  box-sizing: border-box;
  align-content: center;
  font-size: 26px;
  font-weight: 500;
  text-decoration: none;
  color: #333;
  border: 2px solid transparent;
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  transition: all 0.25s ease;
}

.nav-item:nth-child(1) {
  background: #D9D9D9;
}

.nav-item:nth-child(2) {
  background: #2A7CD9;
  color: white;
}

.nav-item:hover {
  opacity: 0.9;
}

.nav-item.active {
  opacity: 1;
}

hr {
  margin: 10px 0;
  opacity: 0.4;
}

#app {
  max-width: 1200px;
  margin: auto;
  font-family: Nunito, "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
  text-align: center;
  background-color: #EDEDED;
  min-height: 100vh;
}

:global(html),
:global(body) {
  overflow-x: hidden;
}
</style>
