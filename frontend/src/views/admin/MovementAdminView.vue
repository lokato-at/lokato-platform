<script setup lang="ts">
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminDevice, AdminChild } from "@/stores/adminDataStore";

const store = useAdminDataStore();

function send(device: AdminDevice, child: AdminChild) {
  if (!device.device_key || !child.tracker_uid) return;

  store.sendScanEvent({
    device_key: device.device_key,
    tracker_uid: child.tracker_uid,
  });
}
</script>

<template>
  <div>
    <h2>Movement Simulator</h2>

    <ul>
      <li
        v-for="d in store.devices"
        :key="d.id"
      >
        <strong>{{ d.name }}</strong>

        <button
          v-for="c in store.children"
          :key="c.id"
          @click="send(d, c)"
        >
          {{ c.name }}
        </button>
      </li>
    </ul>
  </div>
</template>
