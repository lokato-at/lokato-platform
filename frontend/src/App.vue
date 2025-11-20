<template>
  <div id="app">
    <h1>Lokato Plattform</h1>

    <!-- Navigation -->
    <nav class="nav">
      <button @click="$router.push('/dashboard')">Dashboard</button>
      <button @click="$router.push('/admin')">Admin</button>
      <button @click="$router.push('/dev')">Dev</button>
    </nav>

    <hr />

    <!-- Hier werden die Views angezeigt -->
    <router-view />
  </div>
</template>

<script setup lang="ts"></script>

<style scoped>
#app {
  max-width: 900px;
  margin: auto;
  font-family: sans-serif;
  text-align: center;
}
.nav {
  display: flex;
  justify-content: center;
  gap: 20px;
  margin: 20px 0;
}
button {
  padding: 10px 20px;
  cursor: pointer;
}
</style>
