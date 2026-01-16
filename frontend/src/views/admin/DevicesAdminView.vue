<script setup lang="ts">
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminDevice } from "@/stores/adminDataStore";

const store = useAdminDataStore();

function remove(device: AdminDevice) {
  if (!device.id) return;
  store.deleteDevice(device.id);
}
</script>

<template>
  <div>
    <h2>Geräte</h2>

    <ul>
      <li v-for="d in store.devices" :key="d.id">
        {{ d.name }}
        <span v-if="d.room"> (Raum: {{ d.room.name }})</span>
        <button @click="remove(d)">Löschen</button>
      </li>
    </ul>
  </div>
</template>
