<template>
  <div class="admin-children">
    <h1>Admin – Kinder verwalten</h1>

    <div v-if="store.loading">⏳ Lade Daten…</div>
    <div v-if="store.error" class="error-box">❌ {{ store.error }}</div>

    <!-- ========================================================= -->
    <!-- CREATE CHILD FORM -->
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
          <input v-model="form.tracker_uid" placeholder="optional" />
        </label>

        <label class="inline">
          <input type="checkbox" v-model="form.is_active" />
          Aktiv
        </label>

        <button type="submit">✔ Kind erstellen</button>
      </form>
    </section>

    <!-- ========================================================= -->
    <!-- CHILD LIST -->
    <!-- ========================================================= -->
    <section>
      <h2>Alle Kinder</h2>

      <div v-if="store.children.length === 0" class="muted">
        Noch keine Kinder vorhanden.
      </div>

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
          <td>{{ child.tracker_uid ?? '–' }}</td>
          <td>{{ child.is_active ? 'Ja' : 'Nein' }}</td>

          <td>
            <button @click="selectEdit(child)">✏ Bearbeiten</button>
            <button class="danger" @click="remove(child.id)">🗑 Löschen</button>
          </td>
        </tr>
        </tbody>
      </table>
    </section>

    <!-- ========================================================= -->
    <!-- EDIT MODAL -->
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
            <button type="submit">💾 Speichern</button>
            <button type="button" @click="cancelEdit">Abbrechen</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref, onMounted } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";

const store = useAdminDataStore();

/* -------------------------------------------------------
   FORM: CREATE
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

    // Form zurücksetzen
    form.name = "";
    form.photo_url = "";
    form.tracker_uid = "";
    form.is_active = true;
  } catch (err) {
    console.error("FEHLER BEIM CREATE:", err);
  }
}

/* -------------------------------------------------------
   EDIT
------------------------------------------------------- */
const edit = ref(null as any);
const editForm = reactive({
  name: "",
  photo_url: "",
  tracker_uid: "",
  is_active: true,
});

function selectEdit(child: any) {
  edit.value = child.id;

  editForm.name = child.name;
  editForm.photo_url = child.photo_url ?? "";
  editForm.tracker_uid = child.tracker_uid ?? "";
  editForm.is_active = child.is_active;
}

async function update() {
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
   DELETE
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
   LOAD INITIAL DATA
------------------------------------------------------- */
onMounted(() => store.loadChildren());
</script>

<style scoped>
.admin-children {
  padding: 25px;
  max-width: 900px;
  margin: auto;
}

form {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 25px;
}

input[type="text"],
input[type="url"],
input {
  padding: 6px;
  border-radius: 4px;
  border: 1px solid #bbb;
}

button {
  padding: 8px 14px;
  border: none;
  cursor: pointer;
  background: #2d7bff;
  color: white;
  border-radius: 4px;
}

button.danger {
  background: #ff4242;
}

.children-table {
  width: 100%;
  border-collapse: collapse;
}

.children-table th,
.children-table td {
  padding: 10px;
  border-bottom: 1px solid #ddd;
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
  align-items: center;
  justify-content: center;
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
