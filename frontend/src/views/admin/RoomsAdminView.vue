<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, reactive, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminRoom } from "@/stores/adminDataStore";
import { useToast } from "@/composables/useToast";
import ConfirmDialog from "@/components/ConfirmDialog.vue";
import { ROOM_ICONS, roomIconUrl } from "@/constants/roomIcons";

type ActiveFilter = "all" | "active" | "inactive";

const store = useAdminDataStore();
const { success, error: toastError } = useToast();
const searchTerm = ref("");
const activeFilter = ref<ActiveFilter>("all");
const editingId = ref<number | null>(null);

const formCardRef = ref<HTMLElement | null>(null);
const nameInputRef = ref<HTMLInputElement | null>(null);

const pendingDelete = ref<AdminRoom | null>(null);
const deleteBusy = ref(false);

// Anzahl Devices die diesem Raum zugeordnet sind — wichtig für die Delete-
// Warnung, weil FK restrictOnDelete heißt: Raum-Delete ist BLOCKIERT solange
// noch Devices drin hängen.
const pendingDeleteDeviceCount = computed(() => {
  if (!pendingDelete.value) return 0;
  return store.devices.filter((d) => d.room_id === pendingDelete.value!.id).length;
});

const form = reactive({
  name: "",
  area: "",
  icon: "",
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
  form.icon = "";
  form.capacity = "";
  form.tolerance = "";
  form.is_active = true;
}

async function openEdit(room: AdminRoom) {
  editingId.value = room.id;
  form.name = room.name ?? "";
  form.area = room.area ?? "";
  form.icon = room.icon ?? "";
  form.capacity = room.capacity == null ? "" : String(room.capacity);
  form.tolerance = room.tolerance == null ? "" : String(room.tolerance);
  form.is_active = room.is_active ?? true;

  await nextTick();
  formCardRef.value?.scrollIntoView({ behavior: "smooth", block: "start" });
  nameInputRef.value?.focus();
}

function buildPayload() {
  const payload: Partial<AdminRoom> = {
    name: form.name.trim(),
    area: form.area.trim() || null,
    icon: form.icon || null,
    is_active: supportsIsActive.value || editingId.value ? form.is_active : undefined,
  };

  if (form.capacity !== "") payload.capacity = Number(form.capacity);
  if (form.tolerance !== "") payload.tolerance = Number(form.tolerance);

  return payload;
}

async function saveRoom() {
  if (!form.name.trim()) return;

  const payload = buildPayload();
  const isEdit = editingId.value !== null;
  const roomName = form.name.trim();

  store.clearError();
  if (isEdit) {
    await store.updateRoom(editingId.value as number, payload);
  } else {
    await store.createRoom(payload as Pick<AdminRoom, "name"> & Partial<AdminRoom>);
  }

  if (store.error) {
    toastError(store.error);
    return;
  }
  success(isEdit ? `"${roomName}" wurde gespeichert` : `"${roomName}" wurde erstellt`);
  resetForm();
}

function requestDelete(room: AdminRoom) {
  if (!room.id) return;
  pendingDelete.value = room;
}

async function confirmDelete() {
  if (!pendingDelete.value || deleteBusy.value) return;
  const room = pendingDelete.value;

  deleteBusy.value = true;
  store.clearError();
  await store.deleteRoom(room.id);

  if (store.error) {
    toastError(store.error);
  } else {
    success(`"${room.name}" wurde gelöscht`);
    if (editingId.value === room.id) resetForm();
    pendingDelete.value = null;
  }
  deleteBusy.value = false;
}

onMounted(() => {
  // Devices auch laden, damit wir bei Delete die Cascade-Warnung anzeigen können.
  store.loadRooms();
  store.loadDevices();
  store.connectSSE();
});

onUnmounted(() => {
  store.disconnectSSE();
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

    <section ref="formCardRef" class="form-card">
      <h3>{{ editingId ? "Raum bearbeiten" : "Neuer Raum" }}</h3>
      <form class="form-grid" @submit.prevent="saveRoom">
        <input ref="nameInputRef" v-model="form.name" type="text" class="input" placeholder="Name" required />
        <input v-model="form.area" type="text" class="input" placeholder="Bereich (optional)" />
        <input v-model="form.capacity" type="number" min="0" class="input" placeholder="Kapazität" />
        <input v-model="form.tolerance" type="number" min="0" class="input" placeholder="Toleranz" />

        <label class="checkbox" v-if="supportsIsActive || editingId">
          <input v-model="form.is_active" type="checkbox" />
          Raum aktiv
        </label>

        <div class="icon-picker">
          <span class="icon-picker-label">Bild fürs Tablet (optional)</span>
          <div class="icon-grid">
            <button
              type="button"
              class="icon-option"
              :class="{ selected: !form.icon }"
              title="Kein Bild"
              @click="form.icon = ''"
            >
              <span class="icon-none">–</span>
            </button>
            <button
              v-for="opt in ROOM_ICONS"
              :key="opt.file"
              type="button"
              class="icon-option"
              :class="{ selected: form.icon === opt.file }"
              :title="opt.label"
              @click="form.icon = opt.file"
            >
              <img :src="roomIconUrl(opt.file) ?? ''" :alt="opt.label" />
            </button>
          </div>
        </div>

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
          <button class="delete-btn" @click="requestDelete(room)">Löschen</button>
        </div>
      </li>
    </ul>

    <ConfirmDialog
      :model-value="pendingDelete !== null"
      title="Raum löschen?"
      :message="pendingDelete
        ? (pendingDeleteDeviceCount > 0
          ? `&quot;${pendingDelete.name}&quot; kann nicht gelöscht werden, solange ${pendingDeleteDeviceCount} Gerät(e) zugeordnet sind. Bitte zuerst die Geräte einem anderen Raum zuweisen oder löschen.`
          : `&quot;${pendingDelete.name}&quot; wird gelöscht. Aktuelle Standorte der Kinder werden zurückgesetzt (Kinder selbst bleiben). Die Bewegungs-History bleibt erhalten, zeigt für diesen Raum aber „?“.`)
        : ''"
      :confirm-label="pendingDeleteDeviceCount > 0 ? 'Schließen' : 'Löschen'"
      :variant="pendingDeleteDeviceCount > 0 ? 'default' : 'danger'"
      :busy="deleteBusy"
      @update:model-value="(v) => { if (!v) pendingDelete = null }"
      @confirm="pendingDeleteDeviceCount > 0 ? (pendingDelete = null) : confirmDelete()"
      @cancel="() => (pendingDelete = null)"
    />
  </section>
</template>

<style scoped>
@import '../styles/admin-shared.css';

.toolbar { grid-template-columns: minmax(180px, 1fr) auto; }

/* ----- Raum-Bild-Auswahl ----- */
.icon-picker { grid-column: 1 / -1; display: grid; gap: 8px; }
.icon-picker-label { font-size: 0.9rem; color: #475569; font-weight: 600; }
.icon-grid { display: flex; flex-wrap: wrap; gap: 10px; }
.icon-option {
  width: 56px;
  height: 56px;
  border: 2px solid #e2e8f0;
  border-radius: 12px;
  background: #fff;
  padding: 0;
  cursor: pointer;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: border-color 0.15s, transform 0.1s, box-shadow 0.15s;
}
.icon-option:hover { transform: translateY(-1px); }
.icon-option.selected {
  border-color: #2a7cd9;
  box-shadow: 0 0 0 3px rgba(42, 124, 217, 0.18);
}
.icon-option img { width: 100%; height: 100%; object-fit: cover; display: block; }
.icon-none { color: #94a3b8; font-size: 1.5rem; line-height: 1; }

.room-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
.room-item { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 10px; border: 1px solid #e6edf3; border-radius: 12px; padding: 12px; background: #fff; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); }
@media (max-width: 820px) {
  .form-grid, .toolbar { grid-template-columns: 1fr; }
  .room-item { grid-template-columns: 1fr; align-items: start; }
}

.view-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 0.75rem;
}
</style>
