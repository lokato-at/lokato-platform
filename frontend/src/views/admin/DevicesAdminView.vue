<template>
  <div class="admin-view">
    <h1>Admin – Geräte</h1>

    <p class="desc">Geräte verwalten (z. B. Türscanner). Jedes Gerät gehört zu einem Raum.</p>

    <div v-if="store.loading">⏳ Lade Daten…</div>
    <div v-if="store.error" class="error-box">❌ {{ store.error }}</div>

    <!-- DEVICE LIST -->
    <h2>Alle Geräte</h2>

    <table class="admin-table">
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
        <td>{{ d.room?.name || d.room_id }}</td>
        <td>
          <button @click="selectToEdit(d)">Bearbeiten</button>
          <button class="danger" @click="deleteItem(d.id)">Löschen</button>
        </td>
      </tr>
      </tbody>
    </table>

    <!-- FORM -->
    <h2>{{ editingId ? "Gerät bearbeiten" : "Neues Gerät" }}</h2>

    <form @submit.prevent="saveDevice" class="admin-form">
      <label>
        Name
        <input v-model="form.name" required />
      </label>

      <label>
        Device Key
        <input v-model="form.device_key" required />
      </label>

      <label>
        Raum
        <select v-model.number="form.room_id" required>
          <option disabled value="">Bitte wählen</option>
          <option v-for="r in store.rooms" :key="r.id" :value="r.id">
            {{ r.name }}
          </option>
        </select>
      </label>

      <button type="submit">
        {{ editingId ? "Speichern" : "Erstellen" }}
      </button>
      <button type="button" v-if="editingId" @click="resetForm">Abbrechen</button>
    </form>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";

const store = useAdminDataStore();

const form = reactive({
  name: "",
  device_key: "",
  room_id: 0,
});

const editingId = ref<number | null>(null);

onMounted(async () => {
  await store.loadRooms();
  await store.loadDevices();
});

function selectToEdit(device: any) {
  editingId.value = device.id;
  form.name = device.name;
  form.device_key = device.device_key;
  form.room_id = device.room_id;
}

function resetForm() {
  editingId.value = null;
  form.name = "";
  form.device_key = "";
  form.room_id = 0;
}

async function saveDevice() {
  if (editingId.value) {
    await store.updateDevice(editingId.value, { ...form });
  } else {
    await store.createDevice({ ...form });
  }
  resetForm();
}

async function deleteItem(id: number) {
  if (!confirm("Gerät löschen?")) return;
  await store.deleteDevice(id);
}
</script>

<style scoped>
.admin-view { padding: 20px; max-width: 900px; margin: auto; }
.admin-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
.admin-table th, .admin-table td { padding: 8px; border-bottom: 1px solid #ccc; }
.admin-form { display: flex; flex-direction: column; gap: 12px; max-width: 400px; }
button { padding: 6px 12px; margin-top: 10px; }
button.danger { background: #e74c3c; color: white; }
.error-box { background: #fdd; padding: 10px; border-left: 4px solid red; margin: 10px 0; }
.desc { color: #666; margin-bottom: 20px; }
</style>
