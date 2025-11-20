<template>
  <div class="admin-view">
    <h1>Admin – Räume</h1>

    <p class="desc">
      Verwaltung aller Räume. Kapazität, Toleranz, Standort und Aktiv-Status.
    </p>

    <div v-if="store.loading">⏳ Lade Daten…</div>
    <div v-if="store.error" class="error-box">❌ {{ store.error }}</div>

    <!-- ROOM LIST -->
    <h2>Alle Räume</h2>

    <table class="admin-table">
      <thead>
      <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Bereich</th>
        <th>Kapazität</th>
        <th>Toleranz</th>
        <th>Aktiv?</th>
        <th>Aktionen</th>
      </tr>
      </thead>

      <tbody>
      <tr v-for="room in store.rooms" :key="room.id">
        <td>{{ room.id }}</td>
        <td>{{ room.name }}</td>
        <td>{{ room.area || "-" }}</td>
        <td>{{ room.capacity }}</td>
        <td>{{ room.tolerance }}</td>
        <td>{{ room.is_active ? "Ja" : "Nein" }}</td>
        <td>
          <button @click="selectToEdit(room)">Bearbeiten</button>
          <button class="danger" @click="deleteItem(room.id)">Löschen</button>
        </td>
      </tr>
      </tbody>
    </table>

    <!-- FORM -->
    <h2>{{ editingId ? "Raum bearbeiten" : "Neuen Raum anlegen" }}</h2>

    <form @submit.prevent="saveRoom" class="admin-form">
      <label>
        Name
        <input v-model="form.name" required />
      </label>

      <label>
        Bereich / Etage
        <input v-model="form.area" />
      </label>

      <label>
        Kapazität
        <input type="number" v-model.number="form.capacity" required />
      </label>

      <label>
        Toleranz
        <input type="number" v-model.number="form.tolerance" required />
      </label>

      <label>
        Aktiv?
        <input type="checkbox" v-model="form.is_active" />
      </label>

      <button type="submit">
        {{ editingId ? "Speichern" : "Erstellen" }}
      </button>
      <button type="button" v-if="editingId" @click="resetForm">Abbrechen</button>
    </form>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref, onMounted } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";

const store = useAdminDataStore();

const form = reactive({
  name: "",
  area: "",
  capacity: 10,
  tolerance: 1,
  is_active: true,
});

const editingId = ref<number | null>(null);

onMounted(() => {
  store.loadRooms();
});

function selectToEdit(room: any) {
  editingId.value = room.id;
  form.name = room.name;
  form.area = room.area || "";
  form.capacity = room.capacity;
  form.tolerance = room.tolerance;
  form.is_active = room.is_active;
}

function resetForm() {
  editingId.value = null;
  form.name = "";
  form.area = "";
  form.capacity = 10;
  form.tolerance = 1;
  form.is_active = true;
}

async function saveRoom() {
  if (editingId.value) {
    await store.updateRoom(editingId.value, { ...form });
  } else {
    await store.createRoom({ ...form });
  }
  resetForm();
}

async function deleteItem(id: number) {
  if (!confirm("Diesen Raum wirklich löschen?")) return;
  await store.deleteRoom(id);
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
