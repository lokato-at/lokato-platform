<template>
  <div class="admin-devices">
    <h1>Admin – Geräte</h1>

    <p class="desc">
      Verwaltung aller Geräte (z. B. Türscanner).
      Jedes Gerät gehört genau zu einem Raum.
    </p>

    <!-- Ladeindikator -->
    <div v-if="store.loading" class="info-box">⏳ Lade Daten…</div>

    <!-- Fehlermeldung -->
    <div v-if="store.error" class="error-box">❌ {{ store.error }}</div>

    <!-- ========================================================= -->
    <!-- GERÄTE LISTE -->
    <!-- ========================================================= -->
    <h2>Alle Geräte</h2>

    <p v-if="store.devices.length === 0" class="muted">
      Noch keine Geräte vorhanden.
    </p>

    <table v-else class="devices-table">
      <thead>
      <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Device Key</th>
        <th>Raum</th>
        <th>Aktionen</th>
      </tr>
      </thead>

      <tbody>
      <tr v-for="d in store.devices" :key="d.id">
        <td>{{ d.id }}</td>
        <td>{{ d.name }}</td>
        <td>{{ d.device_key }}</td>
        <td>{{ d.room?.name || "Raum #" + d.room_id }}</td>

        <td class="actions">
          <button class="small-btn" @click="selectToEdit(d)">✏ Bearbeiten</button>
          <button class="danger-btn small-btn" @click="deleteItem(d.id)">🗑 Löschen</button>
        </td>
      </tr>
      </tbody>
    </table>

    <!-- ========================================================= -->
    <!-- FORMULAR BEREICH -->
    <!-- ========================================================= -->
    <h2>{{ editingId ? "Gerät bearbeiten" : "Neues Gerät anlegen" }}</h2>

    <form @submit.prevent="saveDevice" class="admin-form">
      <label>
        Name
        <input v-model="form.name" required placeholder="z. B. Tür links" />
      </label>

      <label>
        Device Key
        <input v-model="form.device_key" required placeholder="Eindeutige ID des Scanners" />
      </label>

      <label>
        Raum wählen
        <select v-model.number="form.room_id" required>
          <option disabled value="">Bitte Raum wählen…</option>

          <option v-for="r in store.rooms" :key="r.id" :value="r.id">
            {{ r.name }} ({{ r.area || "?" }})
          </option>
        </select>
      </label>

      <div class="form-actions">
        <button type="submit" class="primary-btn">
          {{ editingId ? "💾 Speichern" : "➕ Erstellen" }}
        </button>

        <button
          type="button"
          v-if="editingId"
          @click="resetForm"
          class="small-btn"
        >
          Abbrechen
        </button>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
/*
|--------------------------------------------------------------------------
| ADMIN – GERÄTE
|--------------------------------------------------------------------------
| Dieser View verwaltet:
| - Geräte laden
| - Geräte erstellen
| - Geräte bearbeiten
| - Geräte löschen
| Die Daten stammen aus dem Admin-Pinia-Store.
*/
import { onMounted, reactive, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";

const store = useAdminDataStore();

/*
|--------------------------------------------------------------------------
| FORMULAR-STATE
|--------------------------------------------------------------------------
*/
const form = reactive({
  name: "",
  device_key: "",
  room_id: 0,
});

/*
|--------------------------------------------------------------------------
| BEARBEITUNGSMODUS
|--------------------------------------------------------------------------
| editingId = null  -> Neues Gerät
| editingId = X     -> Gerät mit ID X bearbeiten
*/
const editingId = ref<number | null>(null);

/*
|--------------------------------------------------------------------------
| INITIAL LOAD
|--------------------------------------------------------------------------
| Ladet Räume und Geräte gleichzeitig
*/
onMounted(async () => {
  await store.loadRooms();
  await store.loadDevices();
});

/*
|--------------------------------------------------------------------------
| FORMULAR MIT GERÄTEDATEN FÜLLEN
|--------------------------------------------------------------------------
*/
function selectToEdit(device: any) {
  editingId.value = device.id;

  form.name = device.name;
  form.device_key = device.device_key;
  form.room_id = device.room_id;
}

/*
|--------------------------------------------------------------------------
| FORMULAR ZURÜCKSETZEN
|--------------------------------------------------------------------------
*/
function resetForm() {
  editingId.value = null;

  form.name = "";
  form.device_key = "";
  form.room_id = 0;
}

/*
|--------------------------------------------------------------------------
| SPEICHERN (ERSTELLEN ODER UPDATE)
|--------------------------------------------------------------------------
*/
async function saveDevice() {
  if (editingId.value) {
    await store.updateDevice(editingId.value, { ...form });
  } else {
    await store.createDevice({ ...form });
  }

  resetForm();
}

/*
|--------------------------------------------------------------------------
| LÖSCHEN
|--------------------------------------------------------------------------
*/
async function deleteItem(id: number) {
  if (!confirm("Gerät wirklich löschen?")) return;
  await store.deleteDevice(id);
}
</script>

<style scoped>
.admin-devices {
  padding: 30px;
  max-width: 900px;
  margin: auto;
  font-family: system-ui, sans-serif;
}

.desc {
  color: #666;
  margin-bottom: 20px;
}

/* INFO / ERROR BOXEN */
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
}

/* TABELLE */
.devices-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 25px;
}
.devices-table th,
.devices-table td {
  padding: 10px;
  border-bottom: 1px solid #ddd;
}
.actions {
  display: flex;
  gap: 8px;
}

/* FORMULAR */
.admin-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
  max-width: 400px;
}
input,
select {
  padding: 8px;
  border: 1px solid #bbb;
  border-radius: 6px;
}

/* BUTTONS */
.primary-btn {
  background: #2d7bff;
  color: white;
  border-radius: 6px;
  padding: 10px 16px;
  cursor: pointer;
}
.small-btn {
  padding: 6px 10px;
  font-size: 13px;
  border-radius: 6px;
}
.danger-btn {
  background: #ff4242 !important;
  color: white;
}
</style>
