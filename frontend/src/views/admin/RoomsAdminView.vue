<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminRoom } from "@/stores/adminDataStore";

type ActiveFilter = "all" | "active" | "inactive";

const store = useAdminDataStore();
const searchTerm = ref("");
const activeFilter = ref<ActiveFilter>("all");
const editingId = ref<number | null>(null);

const form = reactive({
  name: "",
  area: "",
  capacity: "",
  tolerance: "",
  is_active: true,
});

const normalizedSearch = computed(() => searchTerm.value.trim().toLowerCase());

const supportsIsActive = computed(() =>
  store.rooms.some((room) => typeof room.is_active === "boolean"),
);

const filteredRooms = computed<AdminRoom[]>(() => {
  const query = normalizedSearch.value;

  return store.rooms.filter((room) => {
    const name = room.name?.toLowerCase() ?? "";
    const area = room.area?.toLowerCase() ?? "";
    const matchesSearch = !query || name.includes(query) || area.includes(query);

    if (!matchesSearch) return false;

    if (!supportsIsActive.value || activeFilter.value === "all") return true;
    if (activeFilter.value === "active") return room.is_active === true;
    return room.is_active === false;
  });
});

function statusLabel(room: AdminRoom) {
  if (typeof room.is_active !== "boolean") return "Unbekannt";
  return room.is_active ? "Aktiv" : "Inaktiv";
}

function statusClass(room: AdminRoom) {
  if (typeof room.is_active !== "boolean") return "neutral";
  return room.is_active ? "active" : "inactive";
}

function resetForm() {
  editingId.value = null;
  form.name = "";
  form.area = "";
  form.capacity = "";
  form.tolerance = "";
  form.is_active = true;
}

function openEdit(room: AdminRoom) {
  editingId.value = room.id;
  form.name = room.name ?? "";
  form.area = room.area ?? "";
  form.capacity = room.capacity == null ? "" : String(room.capacity);
  form.tolerance = room.tolerance == null ? "" : String(room.tolerance);
  form.is_active = room.is_active ?? true;
}

function buildPayload() {
  const payload: Partial<AdminRoom> = {
    name: form.name.trim(),
    area: form.area.trim() || null,
    is_active: supportsIsActive.value || editingId.value ? form.is_active : undefined,
  };

  if (form.capacity !== "") payload.capacity = Number(form.capacity);
  if (form.tolerance !== "") payload.tolerance = Number(form.tolerance);

  return payload;
}

async function saveRoom() {
  if (!form.name.trim()) return;

  const payload = buildPayload();

  if (editingId.value) {
    await store.updateRoom(editingId.value, payload);
  } else {
    await store.createRoom(payload as Pick<AdminRoom, "name"> & Partial<AdminRoom>);
  }

  resetForm();
}

async function remove(room: AdminRoom) {
  if (!room.id) return;
  await store.deleteRoom(room.id);
  if (editingId.value === room.id) resetForm();
}

onMounted(() => {
  store.loadRooms();
});
</script>

<template>
  <section class="admin-view">
    <header class="view-header">
      <div>
        <h2>Räume</h2>
        <p class="muted">Räume anlegen, bearbeiten und über Status filtern.</p>
      </div>
    </header>

    <p v-if="store.error" class="error">{{ store.error }}</p>

    <section class="form-card">
      <h3>{{ editingId ? "Raum bearbeiten" : "Neuer Raum" }}</h3>
      <form class="form-grid" @submit.prevent="saveRoom">
        <input v-model="form.name" type="text" class="input" placeholder="Name" required />
        <input v-model="form.area" type="text" class="input" placeholder="Bereich (optional)" />
        <input v-model="form.capacity" type="number" min="0" class="input" placeholder="Kapazität" />
        <input v-model="form.tolerance" type="number" min="0" class="input" placeholder="Toleranz" />

        <label class="checkbox" v-if="supportsIsActive || editingId">
          <input v-model="form.is_active" type="checkbox" />
          Raum aktiv
        </label>

        <div class="form-actions">
          <button class="primary-btn" type="submit">{{ editingId ? "Speichern" : "Raum erstellen" }}</button>
          <button v-if="editingId" type="button" class="secondary-btn" @click="resetForm">Abbrechen</button>
        </div>
      </form>
    </section>

    <div class="toolbar">
      <input
        v-model="searchTerm"
        type="search"
        class="input"
        placeholder="Raum suchen…"
        aria-label="Raum suchen"
      />

      <select
        v-if="supportsIsActive"
        v-model="activeFilter"
        class="select"
        aria-label="Nach Status filtern"
      >
        <option value="all">Alle Status</option>
        <option value="active">Nur aktiv</option>
        <option value="inactive">Nur inaktiv</option>
      </select>
    </div>

    <div v-if="store.loading" class="empty-state">Lade Räume…</div>

    <div v-else-if="!filteredRooms.length" class="empty-state">
      Keine Räume gefunden. Passe Suche oder Filter an.
    </div>

    <ul v-else class="room-list">
      <li v-for="room in filteredRooms" :key="room.id" class="room-item">
        <div>
          <p class="title">{{ room.name }}</p>
          <p class="meta">Bereich: {{ room.area || "—" }}</p>
          <p class="meta">Kapazität: {{ room.capacity ?? "∞" }} • Toleranz: {{ room.tolerance ?? "—" }}</p>
        </div>

        <span class="badge" :class="statusClass(room)">{{ statusLabel(room) }}</span>

        <div class="actions">
          <button class="edit-btn" @click="openEdit(room)">Bearbeiten</button>
          <button class="delete-btn" @click="remove(room)">Löschen</button>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
@import '../styles/admin-shared.css';

.toolbar { grid-template-columns: minmax(180px, 1fr) auto; }
.room-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
.room-item { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 10px; border: 1px solid #e6edf3; border-radius: 12px; padding: 12px; background: #fff; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); }
@media (max-width: 820px) {
  .form-grid, .toolbar { grid-template-columns: 1fr; }
  .room-item { grid-template-columns: 1fr; align-items: start; }
}
</style>
