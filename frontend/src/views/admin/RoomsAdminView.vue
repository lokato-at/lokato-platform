<template>
  <div class="admin-rooms">
    <h1>Admin – Räume</h1>

    <p class="desc">
      Verwaltung aller Räume. Kapazität, Toleranz, Bereich und Aktiv-Status.
    </p>

    <!-- Ladeindikator -->
    <div v-if="store.loading" class="info-box">⏳ Lade Daten…</div>

    <!-- Fehlermeldung -->
    <div v-if="store.error" class="error-box">❌ {{ store.error }}</div>

    <!-- ========================================================= -->
    <!-- RAUMLISTE -->
    <!-- ========================================================= -->
    <h2>Alle Räume</h2>

    <p v-if="store.rooms.length === 0" class="muted">Keine Räume vorhanden.</p>

    <table v-else class="rooms-table">
      <thead>
      <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Bereich</th>
        <th>Kapazität</th>
        <th>Toleranz</th>
        <th>Aktiv</th>
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

        <td class="actions">
          <button class="small-btn" @click="selectToEdit(room)">✏ Bearbeiten</button>
          <button class="danger-btn small-btn" @click="deleteItem(room.id)">🗑 Löschen</button>
        </td>
      </tr>
      </tbody>
    </table>

    <!-- ========================================================= -->
    <!-- FORMULAR: RAUM ERSTELLEN / BEARBEITEN -->
    <!-- ========================================================= -->
    <h2>{{ editingId ? "Raum bearbeiten" : "Neuen Raum anlegen" }}</h2>

    <form @submit.prevent="saveRoom" class="admin-form">
      <label>
        Name
        <input v-model="form.name" required />
      </label>

      <label>
        Bereich / Etage
        <input v-model="form.area" placeholder="z.B. EG / UG" />
      </label>

      <label>
        Kapazität
        <input type="number" v-model.number="form.capacity" required min="1" />
      </label>

      <label>
        Toleranz
        <input type="number" v-model.number="form.tolerance" required min="0" />
      </label>

      <label class="inline">
        <input type="checkbox" v-model="form.is_active" />
        Aktiv
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
| RAUM-ADMIN VIEW
|--------------------------------------------------------------------------
| Dieser View ermöglicht:
| - Laden aller Räume
| - Erstellen eines neuen Raums
| - Bearbeiten eines bestehenden Raums
| - Löschen eines Raums
| Alle Daten kommen aus dem Pinia-AdminStore.
*/
import { reactive, ref, onMounted } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";

const store = useAdminDataStore();

/*
|--------------------------------------------------------------------------
| REAKTIVES FORMULAR
|--------------------------------------------------------------------------
| Wird sowohl zum Erstellen als auch zum Bearbeiten benutzt.
*/
const form = reactive({
  name: "",
  area: "",
  capacity: 10,
  tolerance: 1,
  is_active: true,
});

/*
|--------------------------------------------------------------------------
| editingId
|--------------------------------------------------------------------------
| Wenn editingId = null → wir sind im "Neuen Raum anlegen" Modus
| Wenn editingId = X → wir bearbeiten Raum mit ID X
*/
const editingId = ref<number | null>(null);

/*
|--------------------------------------------------------------------------
| INITIAL LOAD
|--------------------------------------------------------------------------
*/
onMounted(() => {
  store.loadRooms();
});

/*
|--------------------------------------------------------------------------
| RAUM FÜR BEARBEITUNG LADEN
|--------------------------------------------------------------------------
*/
function selectToEdit(room: any) {
  editingId.value = room.id;

  form.name = room.name;
  form.area = room.area || "";
  form.capacity = room.capacity;
  form.tolerance = room.tolerance;
  form.is_active = room.is_active;
}

/*
|--------------------------------------------------------------------------
| FORMULAR ZURÜCKSETZEN
|--------------------------------------------------------------------------
*/
function resetForm() {
  editingId.value = null;

  form.name = "";
  form.area = "";
  form.capacity = 10;
  form.tolerance = 1;
  form.is_active = true;
}

/*
|--------------------------------------------------------------------------
| SPEICHERN (Erstellen oder Updaten)
|--------------------------------------------------------------------------
*/
async function saveRoom() {
  if (editingId.value) {
    // Raum bearbeiten
    await store.updateRoom(editingId.value, { ...form });
  } else {
    // Raum neu anlegen
    await store.createRoom({ ...form });
  }

  resetForm();
}

/*
|--------------------------------------------------------------------------
| LÖSCHEN
|--------------------------------------------------------------------------
*/
async function deleteItem(id: number) {
  if (!confirm("Diesen Raum wirklich löschen?")) return;

  await store.deleteRoom(id);
}
</script>

<style scoped>
.admin-rooms {
  padding: 30px;
  max-width: 900px;
  margin: auto;
  font-family: system-ui, sans-serif;
}

.desc {
  color: #666;
  margin-bottom: 20px;
}

/* INFO + ERROR BOX */
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

/* TABLE */
.rooms-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 25px;
}
.rooms-table th,
.rooms-table td {
  padding: 10px;
  border-bottom: 1px solid #ddd;
}
.actions {
  display: flex;
  gap: 8px;
}

/* FORM */
.admin-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
  max-width: 400px;
}

input {
  padding: 8px;
  border: 1px solid #bbb;
  border-radius: 6px;
}

.inline {
  display: flex;
  align-items: center;
  gap: 8px;
}

.form-actions {
  display: flex;
  gap: 10px;
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
