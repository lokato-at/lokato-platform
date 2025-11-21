<template>
  <div class="admin-children">
    <h1>Admin – Kinder verwalten</h1>

    <!-- Ladeindikator -->
    <div v-if="store.loading" class="info-box">⏳ Lade Daten…</div>

    <!-- Fehlermeldung -->
    <div v-if="store.error" class="error-box">❌ {{ store.error }}</div>

    <!-- ========================================================= -->
    <!-- NEUES KIND ERSTELLEN -->
    <!-- ========================================================= -->
    <section class="form-section">
      <h2>Neues Kind anlegen</h2>

      <form @submit.prevent="create">
        <label>
          Name:
          <input v-model="form.name" required placeholder="z.B. Anna Muster" />
        </label>

        <label>
          Foto-URL:
          <input v-model="form.photo_url" placeholder="optional" />
        </label>

        <label>
          Tracker UID:
          <input v-model="form.tracker_uid" placeholder="z.B. TAG-1234" />
        </label>

        <label class="inline">
          <input type="checkbox" v-model="form.is_active" />
          Aktiv
        </label>

        <button type="submit" class="primary-btn">✔ Kind erstellen</button>
      </form>
    </section>

    <!-- ========================================================= -->
    <!-- KINDER-LISTE -->
    <!-- ========================================================= -->
    <section>
      <h2>Alle Kinder</h2>

      <p v-if="store.children.length === 0" class="muted">
        Noch keine Kinder vorhanden.
      </p>

      <table v-else class="children-table">
        <thead>
        <tr>
          <th>Name</th>
          <th>Tracker</th>
          <th>Aktiv</th>
          <th>Aktionen</th>
        </tr>
        </thead>

        <tbody>
        <tr v-for="child in store.children" :key="child.id">
          <td>{{ child.name }}</td>
          <td>{{ child.tracker_uid ?? "–" }}</td>
          <td>{{ child.is_active ? "Ja" : "Nein" }}</td>

          <td class="actions">
            <button @click="selectEdit(child)" class="small-btn">✏ Bearbeiten</button>
            <button @click="remove(child.id)" class="danger-btn small-btn">🗑 Löschen</button>
          </td>
        </tr>
        </tbody>
      </table>
    </section>

    <!-- ========================================================= -->
    <!-- MODAL: KIND BEARBEITEN -->
    <!-- ========================================================= -->
    <div v-if="edit" class="modal">
      <div class="modal-box">
        <h3>Kind bearbeiten</h3>

        <form @submit.prevent="update">
          <label>
            Name:
            <input v-model="editForm.name" required />
          </label>

          <label>
            Foto-URL:
            <input v-model="editForm.photo_url" />
          </label>

          <label>
            Tracker UID:
            <input v-model="editForm.tracker_uid" />
          </label>

          <label class="inline">
            <input type="checkbox" v-model="editForm.is_active" />
            Aktiv
          </label>

          <div class="modal-actions">
            <button type="submit" class="primary-btn small-btn">💾 Speichern</button>
            <button type="button" class="small-btn" @click="cancelEdit">Abbrechen</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
/*
|--------------------------------------------------------------------------
| LOGIK
|--------------------------------------------------------------------------
| - Laden der Kinder
| - Erstellen neuer Kinder
| - Bearbeiten bestehender Kinder (Modal)
| - Löschen eines Kindes
*/
import { reactive, ref, onMounted } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";

const store = useAdminDataStore();

/* -------------------------------------------------------
   FORMULAR: NEUES KIND
------------------------------------------------------- */
const form = reactive({
  name: "",
  photo_url: "",
  tracker_uid: "",
  is_active: true,
});

async function create() {
  try {
    await store.createChild({
      name: form.name,
      photo_url: form.photo_url,
      tracker_uid: form.tracker_uid,
      is_active: form.is_active,
    });

    // Formular resetten
    form.name = "";
    form.photo_url = "";
    form.tracker_uid = "";
    form.is_active = true;
  } catch (err) {
    console.error("FEHLER BEIM CREATE:", err);
  }
}

/* -------------------------------------------------------
   MODAL: BEARBEITUNG
------------------------------------------------------- */
const edit = ref<number | null>(null);

const editForm = reactive({
  name: "",
  photo_url: "",
  tracker_uid: "",
  is_active: true,
});

// Modal öffnen → Daten des Kindes laden
function selectEdit(child: any) {
  edit.value = child.id;

  editForm.name = child.name;
  editForm.photo_url = child.photo_url ?? "";
  editForm.tracker_uid = child.tracker_uid ?? "";
  editForm.is_active = child.is_active;
}

// Kind speichern
async function update() {
  if (!edit.value) return;

  try {
    await store.updateChild(edit.value, {
      name: editForm.name,
      photo_url: editForm.photo_url,
      tracker_uid: editForm.tracker_uid,
      is_active: editForm.is_active,
    });

    edit.value = null;
  } catch (err) {
    console.error("FEHLER BEIM UPDATE:", err);
  }
}

function cancelEdit() {
  edit.value = null;
}

/* -------------------------------------------------------
   LÖSCHEN
------------------------------------------------------- */
async function remove(id: number) {
  if (!confirm("Wirklich löschen?")) return;

  try {
    await store.deleteChild(id);
  } catch (err) {
    console.error("FEHLER BEIM DELETE:", err);
  }
}

/* -------------------------------------------------------
   INITIAL LOAD
------------------------------------------------------- */
onMounted(() => store.loadChildren());
</script>

<style scoped>
/* PAGE LAYOUT */
.admin-children {
  padding: 30px;
  max-width: 900px;
  margin: auto;
  font-family: system-ui, sans-serif;
}

/* INFO + ERROR BOXES */
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

/* FORMS */
form {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 25px;
}

input {
  padding: 8px;
  border-radius: 6px;
  border: 1px solid #ccc;
}

.inline {
  display: flex;
  align-items: center;
  gap: 8px;
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

/* TABLE */
.children-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 10px;
}
.children-table th,
.children-table td {
  padding: 10px;
  border-bottom: 1px solid #ddd;
}
.actions {
  display: flex;
  gap: 8px;
}

.muted {
  color: #777;
}

/* MODAL */
.modal {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  display: flex;
  justify-content: center;
  align-items: center;
}
.modal-box {
  background: white;
  padding: 20px;
  width: 350px;
  border-radius: 8px;
}
.modal-actions {
  display: flex;
  justify-content: space-between;
  margin-top: 15px;
}
</style>
