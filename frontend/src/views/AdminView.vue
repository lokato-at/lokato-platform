<template>
  <div class="admin-home">
    <h1>Admin Bereich</h1>

    <p class="desc">
      Verwaltung aller Daten. Wähle eine Kategorie aus, um Inhalte zu bearbeiten
      oder neue Einträge zu erstellen.
    </p>

    <!-- Loading / Error -->
    <div v-if="store.loading" class="loading">⏳ Lade Admin-Daten…</div>
    <div v-if="store.error" class="error-box">❌ {{ store.error }}</div>

    <!-- Dashboard Panels -->
    <div class="grid">
      <!-- Kinder -->
      <div class="card">
        <h2>Kinder</h2>
        <p>Gesamt: <strong>{{ store.children.length }}</strong></p>
        <button @click="$router.push('/admin/children')">
          Verwaltung öffnen
        </button>
      </div>

      <!-- Räume -->
      <div class="card">
        <h2>Räume</h2>
        <p>Gesamt: <strong>{{ store.rooms.length }}</strong></p>
        <button @click="$router.push('/admin/rooms')">
          Verwaltung öffnen
        </button>
      </div>

      <!-- Geräte -->
      <div class="card">
        <h2>Geräte</h2>
        <p>Gesamt: <strong>{{ store.devices.length }}</strong></p>
        <button @click="$router.push('/admin/devices')">
          Verwaltung öffnen
        </button>
      </div>

      <!-- Movements -->
      <div class="card">
        <h2>Bewegungen (Test)</h2>
        <p>
          Erstelle manuelle Bewegungsereignisse (Scan), um den
          Bewegungsfluss zu testen.
        </p>
        <button @click="$router.push('/admin/movements')">
          Movement Tool öffnen
        </button>
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
}

.desc {
  margin-bottom: 20px;
  color: #666;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 20px;
}

.card {
  background: #f8f8f8;
  padding: 20px;
  border-radius: 10px;
  border: 1px solid #ddd;
}

.card h2 {
  margin: 0 0 10px;
}

button {
  margin-top: 10px;
  padding: 8px 12px;
}

.error-box {
  background: #fdd;
  padding: 10px;
  border-left: 4px solid red;
}

.loading {
  margin: 20px 0;
}
</style>
