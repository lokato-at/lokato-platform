<script setup lang="ts">
import { computed } from "vue";
import { useDashboardDataStore } from "@/stores/dashboardDataStore";
import type { Room, Child, Movement, OccupancySnapshot } from "@/stores/dashboardDataStore";

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
</script>

<template>
  <div class="dashboard">
    <div
      v-for="room in rooms"
      :key="room.id"
      class="room"
      :class="{
        over: room.status?.over_capacity,
        warn: room.status?.within_tolerance,
      }"
    >
      <h2>{{ room.name }}</h2>

      <p>
        Belegung:
        {{ occupancyFor(room.id).children.length }}
        /
        {{ room.capacity ?? "∞" }}
      </p>

      <ul>
        <li
          v-for="child in occupancyFor(room.id).children"
          :key="child.id"
        >
          {{ child.name }}
        </li>
      </ul>
    </div>

    <section class="movements">
      <h3>Letzte Bewegungen</h3>

      <ul>
        <li v-for="m in store.latestMovements" :key="m.id">
          <span>{{ m.child?.name ?? "?" }}</span>
          →
          <span>{{ m.room?.name ?? "?" }}</span>
          @ {{ formatTime(m.occurred_at) }}
        </li>
      </ul>
    </section>
  </div>
</template>
