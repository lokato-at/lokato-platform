<script setup lang="ts">
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminRoom } from "@/stores/adminDataStore";

const store = useAdminDataStore();

function remove(room: AdminRoom) {
  if (!room.id) return;
  store.deleteRoom(room.id);
}
</script>

<template>
  <div>
    <h2>Räume</h2>

    <ul>
      <li v-for="room in store.rooms" :key="room.id">
        {{ room.name }}
        <span v-if="room.area"> ({{ room.area }})</span>
        <button @click="remove(room)">Löschen</button>
      </li>
    </ul>
  </div>
</template>
