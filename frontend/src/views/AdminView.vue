<template>
  <div class="admin-home">
    <h3 class="desc">
      Zentrale Verwaltung aller Daten. Wähle eine Kategorie, um Inhalte zu bearbeiten oder neue
      Datensätze anzulegen.
    </h3>

    <div v-if="store.loading" class="info-box">⏳ Admin-Daten werden geladen…</div>
    <div v-if="store.error" class="error-box">❌ {{ store.error }}</div>

    <div class="grid">
      <div class="card">
        <h2>Kinder</h2>
        <h3 class="count">
          Gesamt: <strong>{{ store.summary.children_count }}</strong>
        </h3>
        <h3 class="text-small">Hier kannst du Kinder anlegen, bearbeiten oder löschen.</h3>

        <button class="card-btn" @click="$router.push('/admin/children')">Verwalten</button>
      </div>

      <div class="card">
        <h2>Räume</h2>
        <h3 class="count">
          Gesamt: <strong>{{ store.summary.rooms_count }}</strong>
        </h3>
        <h3 class="text-small">Räume, Kapazitäten und Toleranzen bearbeiten.</h3>

        <button class="card-btn" @click="$router.push('/admin/rooms')">Verwalten</button>
      </div>

      <div class="card">
        <h2>Geräte</h2>
        <h3 class="count">
          Gesamt: <strong>{{ store.summary.devices_count }}</strong>
        </h3>
        <h3 class="text-small">Scanner-Geräte verwalten und Räumen zuordnen.</h3>

        <button class="card-btn" @click="$router.push('/admin/devices')">Verwalten</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useAdminDataStore } from '@/stores/adminDataStore'

const store = useAdminDataStore()

onMounted(() => {
  store.loadAllAdminData()
})
</script>

<style scoped>
.body {
  width: 1200px;
}

.admin-home {
  width: 1200px;
  max-width: 100%;
  padding: 15px 30px 30px 30px;
  margin: 0 auto;
  font-family: system-ui, sans-serif;
  box-sizing: border-box;
}

.desc {
  margin-bottom: 20px;
  color: #666;
  font-size: 15px;
}

h3.desc {
  margin-top: 10px;
  font-size: 21px;
  font-family: Nunito, sans-serif;
  color: #252525;
  font-weight: 550;
}

.info-box {
  background: #e9f3ff;
  border-left: 4px solid #3a8bfd;
  padding: 10px;
  margin-bottom: 20px;
}

.error-box {
  background: #ffeaea;
  border-left: 4px solid #ff3d3d;
  padding: 10px;
  margin-bottom: 10px;
}

.grid {
  display: grid;
  grid-template-columns: repeat(3, 384px);
  gap: 22px;
  justify-content: center;
}

.card {
  width: 384px;
  height: 369px;
  padding: 25px;
  box-sizing: border-box;
  background: #f8f8f8;
  border-radius: 5px;
  border: 1px solid #ddd;
  box-shadow: 0 0 6px rgba(0, 0, 0, 0.06);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

.card h2 {
  font-size: 36px;
  font-family: Nunito, sans-serif;
  margin-top: 0;
  font-weight: bold;
}

.card h3 {
  font-size: 21px;
  font-family: Nunito, sans-serif;
  color: #252525;
  font-weight: 550;
}

.card.wide {
  grid-column: span 1;
}

@media (min-width: 700px) {
  .card.wide {
    grid-column: span 2;
  }
}

.count {
  margin: 6px 0 10px;
  font-size: 16px;
}

.text-small {
  font-size: 14px;
  color: #555;
  margin-bottom: 16px;
}

.card-btn {
  background: #2a7cd9;
  width: 282px;
  height: 56px;
  box-sizing: border-box;
  color: white;
  padding: 10px 16px;
  border-radius: 15px;
  border: none;
  align-self: center;
  margin: 0 auto;
  cursor: pointer;
  font-size: 21px;
  font-weight: 550;
  font-family: Nunito, sans-serif;
  transition: 0.2s;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
}

.card-btn:hover {
  background: #2264d4;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
</style>
