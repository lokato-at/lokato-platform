<script setup lang="ts">
import { onMounted } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminDevice } from "@/stores/adminDataStore";

const store = useAdminDataStore();

function remove(device: AdminDevice) {
  if (!device.id) return;
  store.deleteDevice(device.id);
}

onMounted(() => {
  store.loadDevices();
});
</script>

<template>
  <div>
    <h2>Geräte</h2>
    <p v-if="store.error">{{ store.error }}</p>

    <ul>
      <li v-for="device in store.devices" :key="device.id">
        {{ device.name }}
        <span v-if="device.room"> (Raum: {{ device.room.name }})</span>
        <button @click="remove(device)">Löschen</button>
      </li>
    </ul>
  </div>
</template>
