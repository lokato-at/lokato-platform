<template>
  <div class="admin-home">
    <h1>Admin Bereich</h1>

    <p class="desc">
      Zentrale Verwaltung aller Daten. Wähle eine Kategorie, um Inhalte zu
      bearbeiten oder neue Datensätze anzulegen.
    </p>

    <div v-if="store.loading" class="info-box">⏳ Admin-Daten werden geladen…</div>
    <div v-if="store.error" class="error-box">❌ {{ store.error }}</div>

    <div class="grid">
      <div class="card">
        <h2>Kinder</h2>
        <p class="count">Gesamt: <strong>{{ store.summary.children_count }}</strong></p>
        <p class="text-small">Hier kannst du Kinder anlegen, bearbeiten oder löschen.</p>

        <button class="card-btn" @click="$router.push('/admin/children')">👶 Kinder verwalten</button>
      </div>

      <div class="card">
        <h2>Räume</h2>
        <p class="count">Gesamt: <strong>{{ store.summary.rooms_count }}</strong></p>
        <p class="text-small">Räume, Kapazitäten und Toleranzen bearbeiten.</p>

        <button class="card-btn" @click="$router.push('/admin/rooms')">🏫 Räume verwalten</button>
      </div>

      <div class="card">
        <h2>Geräte</h2>
        <p class="count">Gesamt: <strong>{{ store.summary.devices_count }}</strong></p>
        <p class="text-small">Scanner-Geräte verwalten und Räumen zuordnen.</p>

        <button class="card-btn" @click="$router.push('/admin/devices')">🔧 Geräte verwalten</button>
      </div>

      <div class="card wide">
        <h2>Bewegungen (Test)</h2>
        <p class="text-small">
          Simuliere Bewegungsereignisse, um das Dashboard und die Logik zu testen.
        </p>

        <button class="card-btn" @click="$router.push('/admin/movements')">🚶 Movement Tool öffnen</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";

const store = useAdminDataStore();

onMounted(() => {
  store.loadAllAdminData();
});
</script>

<style scoped>
.admin-home {
  padding: 30px;
  max-width: 1100px;
  margin: auto;
  font-family: system-ui, sans-serif;
}

.desc {
  margin-bottom: 20px;
  color: #666;
  font-size: 15px;
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
  margin-bottom: 20px;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 22px;
}

.card {
  background: #f8f8f8;
  border-radius: 12px;
  padding: 22px;
  border: 1px solid #ddd;
  box-shadow: 0 0 6px rgba(0, 0, 0, 0.06);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

.card h2 {
  margin: 0 0 10px;
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
  background: #2d7bff;
  color: white;
  padding: 10px 16px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  font-size: 15px;
  transition: 0.2s;
}

.card-btn:hover {
  background: #2264d4;
}
</style>
