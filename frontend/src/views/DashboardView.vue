<template>
  <div class="dashboard-view">
    <h1>Dashboard</h1>

    <p class="desc">
      Öffentliche Live-Übersicht aller Räume und der Kinder in jedem Raum.
    </p>

    <div v-if="store.loading">⏳ Lade Daten…</div>

    <div v-if="store.error" class="error-box">
      ❌ {{ store.error }}
    </div>

    <!-- ========================================================= -->
    <!-- 1) RÄUME                                                 -->
    <!-- ========================================================= -->
    <section>
      <h2>Räume</h2>

      <div class="rooms-grid">
        <article
          class="room-card"
          v-for="room in store.rooms"
          :key="room.id"
        >
          <h3>{{ room.name }}</h3>

          <p>
            Kapazität: {{ room.current_count }} / {{ room.capacity }}
          </p>

          <p>
            Status:
            <strong>
              {{
                room.status.over_capacity
                  ? "Überfüllt"
                  : room.status.within_tolerance
                    ? "Toleranzbereich"
                    : "OK"
              }}
            </strong>
          </p>

          <h4>Kinder</h4>

          <ul v-if="store.occupancy[room.id]?.children.length">
            <li
              v-for="child in store.occupancy[room.id].children"
              :key="child.id"
            >
              {{ child.name }}
            </li>
          </ul>

          <p v-else class="muted">Derzeit keine Kinder in diesem Raum.</p>
        </article>
      </div>
    </section>

    <!-- ========================================================= -->
    <!-- 2) LETZTE BEWEGUNGEN                                     -->
    <!-- ========================================================= -->
    <section>
      <h2>Letzte Bewegungen</h2>

      <ol>
        <li
          v-for="m in store.latestMovements"
          :key="m.id"
        >
          {{ m.child?.name ?? "?" }} →
          {{ m.to_room?.name ?? "?" }}
          ({{ formatDate(m.occurred_at) }})
        </li>
      </ol>



      <p v-if="store.latestMovements.length === 0" class="muted">
        Keine Bewegungen vorhanden.
      </p>
    </section>
  </div>
</template>

<script setup lang="ts">
import { onMounted } from "vue";
import { useDashboardDataStore } from "@/stores/dashboardDataStore";

const store = useDashboardDataStore();

onMounted(async () => {
  await store.fetchAllDashboardData();
});

const formatDate = (iso?: string) => {
  if (!iso) return "";
  const d = new Date(iso);
  return d.toLocaleString("de-DE", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
};



</script>

<style scoped>
.dashboard-view {
  padding: 30px;
  max-width: 1000px;
  margin: auto;
}

.rooms-grid {
  display: grid;
  gap: 20px;
  grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
}

.room-card {
  padding: 20px;
  background: #f4f4f4;
  border-radius: 8px;
  border: 1px solid #ddd;
}
.muted {
  color: #777;
}
.error-box {
  background: #fdd;
  padding: 10px;
  border-left: 4px solid red;
}
</style>
