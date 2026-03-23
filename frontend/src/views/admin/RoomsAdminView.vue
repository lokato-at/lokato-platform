<script setup lang="ts">
import { onMounted } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminRoom } from "@/stores/adminDataStore";

const store = useAdminDataStore();

function remove(room: AdminRoom) {
  if (!room.id) return;
  store.deleteRoom(room.id);
}

onMounted(() => {
  store.loadRooms();
});
</script>

<template>
  <div>
    <h2>Räume</h2>
    <p v-if="store.error">{{ store.error }}</p>

    <ul>
      <li v-for="room in store.rooms" :key="room.id">
        {{ room.name }}
        <span v-if="room.area"> ({{ room.area }})</span>
        <button @click="remove(room)">Löschen</button>
      </li>
    </ul>
  </div>
</template>
