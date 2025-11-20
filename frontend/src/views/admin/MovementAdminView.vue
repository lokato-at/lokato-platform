<template>
  <div class="admin-view">
    <h1>Bewegungen testen (Scan Simulator)</h1>

    <p class="desc">
      Erstelle manuelle Bewegungs-Events, um das Dashboard und die Raumlogik zu testen.
    </p>

    <div v-if="store.loading">⏳ Lade…</div>
    <div v-if="store.error" class="error-box">❌ {{ store.error }}</div>

    <form @submit.prevent="sendScan" class="admin-form">
      <label>
        Kind
        <select v-model="childId">
          <option v-for="c in store.children" :value="c.id">{{ c.name }}</option>
        </select>
      </label>

      <label>
        Gerät (Türscanner)
        <select v-model="deviceKey">
          <option v-for="d in store.devices" :value="d.device_key">
            {{ d.name }} (Key: {{ d.device_key }})
          </option>
        </select>
      </label>

      <label>
        Zeitpunkt (optional)
        <input type="datetime-local" v-model="eventTime" />
      </label>

      <button type="submit">Movement erstellen</button>
    </form>

    <h2>Letztes Scan-Ergebnis</h2>
    <pre>{{ format(store.lastScanResult) }}</pre>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";

const store = useAdminDataStore();

const childId = ref<number | null>(null);
const deviceKey = ref<string>("");
const eventTime = ref<string>("");

onMounted(async () => {
  await store.loadChildren();
  await store.loadDevices();
});

function format(data: unknown) {
  return JSON.stringify(data, null, 2);
}

async function sendScan() {
  if (!childId.value || !deviceKey.value) {
    alert("Bitte Kind und Gerät wählen!");
    return;
  }

  const child = store.children.find(c => c.id === childId.value);
  if (!child?.tracker_uid) {
    alert("Dieses Kind hat keinen tracker_uid!");
    return;
  }

  await store.sendScanEvent({
    device_key: deviceKey.value,
    tracker_uid: child.tracker_uid,
    event_time: eventTime.value || undefined,
  });
}
</script>

<style scoped>
.admin-view { padding: 20px; max-width: 900px; margin: auto; }
.admin-form { display: flex; flex-direction: column; gap: 12px; max-width: 400px; }
pre { background: #111; color: #0f0; padding: 10px; border-radius: 8px; }
.error-box { background: #fdd; padding: 10px; border-left: 4px solid red; }
.desc { color: #666; margin-bottom: 20px; }
button { padding: 6px 12px; }
</style>
