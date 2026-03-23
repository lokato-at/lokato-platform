<script setup lang="ts">
import { computed, onMounted, onUnmounted } from "vue";
import { useDashboardDataStore } from "@/stores/dashboardDataStore";
import type { Room, OccupancySnapshot, Movement } from "@/stores/dashboardDataStore";

const store = useDashboardDataStore();

const rooms = computed<Room[]>(() => store.rooms ?? []);

const emptyOccupancy: OccupancySnapshot = {
  children: [],
  current_count: 0,
  room: undefined,
};

function occupancyFor(roomId: number): OccupancySnapshot {
  return store.occupancy[roomId] ?? emptyOccupancy;
}

function formatTime(ts?: string) {
  if (!ts) return "--:--";
  const d = new Date(ts);
  return d.toLocaleTimeString("de-DE", { hour: "2-digit", minute: "2-digit" });
}

function movementDestination(movement: Movement): string {
  return movement.to_room?.name ?? "Unbekannter Raum";
}

onMounted(async () => {
  await store.fetchAllDashboardData();
  store.connectSSE();
});

onUnmounted(() => {
  store.disconnectSSE();
});
</script>

<template>
  <div class="dashboard">
    <header class="dashboard-header">
      <div>
        <h2>Live Dashboard</h2>
        <p>REST lädt den Snapshot, SSE hält nur die Deltas warm.</p>
      </div>
      <span class="connection" :class="{ online: store.sseConnected }">
        {{ store.sseConnected ? "SSE verbunden" : "SSE verbindet…" }}
      </span>
    </header>

    <p v-if="store.loading" class="info">Dashboard wird geladen…</p>
    <p v-else-if="store.error" class="error">{{ store.error }}</p>

    <div class="dashboard-grid">
      <section class="rooms-grid">
        <article
          v-for="room in rooms"
          :key="room.id"
          class="room"
          :class="{
            over: room.status?.over_capacity,
            warn: room.status?.within_tolerance,
          }"
        >
          <div class="room-header">
            <h3>{{ room.name }}</h3>
            <span>{{ occupancyFor(room.id).current_count ?? occupancyFor(room.id).children.length }} / {{ room.capacity ?? "∞" }}</span>
          </div>

          <ul>
            <li v-for="child in occupancyFor(room.id).children" :key="child.id">
              {{ child.name }}
            </li>
          </ul>
        </article>
      </section>

      <section class="movements">
        <h3>Letzte Bewegungen</h3>

        <ul>
          <li v-for="movement in store.latestMovements" :key="movement.id">
            <span>{{ movement.child?.name ?? `Kind #${movement.child_id ?? '?'}` }}</span>
            →
            <span>{{ movementDestination(movement) }}</span>
            @ {{ formatTime(movement.occurred_at) }}
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>

<style scoped>
.dashboard {
  display: grid;
  gap: 20px;
  padding: 0 16px 24px;
}

.dashboard-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  text-align: left;
}

.connection {
  border-radius: 999px;
  padding: 8px 12px;
  background: #fff4cc;
  color: #7a5c00;
  font-weight: 600;
}

.connection.online {
  background: #dcfce7;
  color: #166534;
}

.dashboard-grid {
  display: grid;
  gap: 20px;
}

.rooms-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
}

.room {
  border: 1px solid #dbe4ff;
  border-radius: 14px;
  padding: 16px;
  text-align: left;
  background: #ffffff;
}

.room.warn {
  border-color: #f59e0b;
}

.room.over {
  border-color: #ef4444;
  background: #fff5f5;
}

.room-header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.movements {
  text-align: left;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 16px;
}

.info,
.error {
  text-align: left;
}

.error {
  color: #b91c1c;
}
</style>
