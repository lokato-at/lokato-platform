<template>
  <div id="app">
    <header v-if="!isTabletRoute" class="header">
      <h1>Lokato</h1>
    </header>

    <picture v-if="!isTabletRoute">
      <source media="(max-width: 700px)" srcset="./views/images/hort_pregarten_mobile.webp" />
      <img src="./views/images/hort_pregarten.png" alt="Hort Pregarten" class="header-image" />
    </picture>

    <nav v-if="!isTabletRoute" class="nav">
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

    <hr v-if="!isTabletRoute" />
    <router-view />
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";

const route = useRoute();
const isTabletRoute = computed(() => route.path.startsWith("/tablet"));
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
  box-shadow: 0px 4px 13px 0px rgba(0, 0, 0, 0.25);
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
  height: 59px;
  width: 262px;
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
  box-shadow: 0px 4px 4px 0px rgba(0, 0, 0, 0.25);
  transition: all 0.25s ease;
  background: #D9D9D9;
}

.nav-item.active {
  background: #2A7CD9;
  color: white;
}

.nav-item:hover {
  opacity: 0.9;
}


hr {
  margin: 0px 0;
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

@media (max-width: 700px) {
  .nav {
    display: none;
  }

  .header {
    position: relative;
    z-index: 10;
  }

  .header-image {
    height: 220px;
    margin-top: -20px;
    margin-bottom: 0px;
    object-fit: cover;
    object-position: center;
    z-index: 1;
  }
}
</style>
