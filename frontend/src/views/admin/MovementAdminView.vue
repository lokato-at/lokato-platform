<template>
  <div class="admin-movement">
    <h1>Admin – Bewegungen testen (Scan-Simulator)</h1>

    <p class="desc">
      Mit diesem Tool kannst du manuelle Bewegungs-Events erzeugen.
      Das simuliert, was ein echter Tür-Scanner melden würde.
    </p>

    <!-- Ladeindikator -->
    <div v-if="store.loading" class="info-box">⏳ Lade Daten…</div>

    <!-- Fehleranzeige -->
    <div v-if="store.error" class="error-box">❌ {{ store.error }}</div>

    <!-- ========================================================= -->
    <!-- FORMULAR: Scan-Ereignis erzeugen -->
    <!-- ========================================================= -->
    <form @submit.prevent="sendScan" class="admin-form">
      <!-- KIND -->
      <label>
        Kind auswählen
        <select v-model.number="childId" required>
          <option disabled value="">Bitte wählen…</option>
          <option v-for="c in store.children" :key="c.id" :value="c.id">
            {{ c.name }} ({{ c.tracker_uid || "kein UID" }})
          </option>
        </select>
      </label>

      <!-- GERÄT -->
      <label>
        Gerät (Türscanner)
        <select v-model="deviceKey" required>
          <option disabled value="">Bitte wählen…</option>
          <option v-for="d in store.devices" :key="d.id" :value="d.device_key">
            {{ d.name }} – ROOM #{{ d.room_id }} (Key: {{ d.device_key }})
          </option>
        </select>
      </label>

      <!-- Zeitpunkt -->
      <label>
        Zeitpunkt (optional)
        <input type="datetime-local" v-model="eventTime" />
      </label>

      <!-- SUBMIT -->
      <button class="primary-btn" type="submit">➕ Movement erstellen</button>
    </form>

    <!-- ========================================================= -->
    <!-- LETZTES ERGEBNIS -->
    <!-- ========================================================= -->
    <h2>Letztes Scan-Ergebnis</h2>

    <pre v-if="store.lastScanResult" class="result-box">
{{ format(store.lastScanResult) }}
    </pre>

    <p v-else class="muted">
      Noch kein Test durchgeführt.
    </p>
  </div>
</template>

<script setup lang="ts">
/*
|--------------------------------------------------------------------------
| MovementAdminView
|--------------------------------------------------------------------------
| Simuliert POST /scan aus dem Backend.
| Auswahl: Kind – Gerät – Zeit -> sendScanEvent()
| Der AdminStore kümmert sich um die API.
*/
import { onMounted, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";

const store = useAdminDataStore();

/* Formularfelder */
const childId = ref<number | null>(null);
const deviceKey = ref<string>("");
const eventTime = ref<string>("");

/*
|--------------------------------------------------------------------------
| Initial: Kinder & Geräte laden
|--------------------------------------------------------------------------
*/
onMounted(async () => {
  await store.loadChildren();
  await store.loadDevices();
});

/*
|--------------------------------------------------------------------------
| JSON schöner anzeigen
|--------------------------------------------------------------------------
*/
function format(data: unknown) {
  return JSON.stringify(data, null, 2);
}

/*
|--------------------------------------------------------------------------
| SCAN senden
|--------------------------------------------------------------------------
*/
async function sendScan() {
  if (!childId.value || !deviceKey.value) {
    alert("Bitte Kind und Gerät auswählen!");
    return;
  }

  const child = store.children.find((c) => c.id === childId.value);

  if (!child?.tracker_uid) {
    alert("Dieses Kind hat keinen tracker_uid – kann nicht gescannt werden!");
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
.admin-movement {
  padding: 30px;
  max-width: 900px;
  margin: auto;
  font-family: system-ui, sans-serif;
}

.desc {
  color: #666;
  margin-bottom: 20px;
}

/* INFO & ERROR BOXEN */
.info-box {
  background: #e9f3ff;
  border-left: 4px solid #3a8bfd;
  padding: 10px;
  margin-bottom: 20px;
}
.error-box {
  background: #ffeaea;
  border-left: 4px solid #ff3d3d;
  padding: 10px;
  margin-bottom: 20px;
}

.muted {
  color: #777;
  margin-top: 10px;
}

/* FORMULAR */
.admin-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
  max-width: 400px;
  margin-bottom: 40px;
}

input,
select {
  padding: 8px;
  border: 1px solid #bbb;
  border-radius: 6px;
  width: 100%;
}

/* BUTTONS */
.primary-btn {
  background: #2d7bff;
  color: white;
  border-radius: 6px;
  padding: 10px 16px;
  cursor: pointer;
}

/* RESULT AREA */
.result-box {
  background: #111;
  color: #0f0;
  padding: 14px;
  border-radius: 8px;
  overflow-x: auto;
}
</style>
