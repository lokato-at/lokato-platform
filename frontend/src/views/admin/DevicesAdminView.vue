<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminDevice, AdminRoom } from "@/stores/adminDataStore";

type ActiveFilter = "all" | "active" | "inactive";

const store = useAdminDataStore();
const searchTerm = ref("");
const roomFilter = ref<string>("all");
const activeFilter = ref<ActiveFilter>("all");
const editingId = ref<number | null>(null);

const form = reactive({
  name: "",
  device_key: "",
  room_id: "",
  is_active: true,
});

function activeValue(entity: unknown): boolean | undefined {
  if (!entity || typeof entity !== "object") return undefined;
  const record = entity as Record<string, unknown>;
  if (typeof record.is_active === "boolean") return record.is_active;
  if (typeof record.isActive === "boolean") return record.isActive;
  return undefined;
}

const normalizedSearch = computed(() => searchTerm.value.trim().toLowerCase());

const roomOptions = computed<AdminRoom[]>(() =>
  store.rooms
    .slice()
    .sort((a, b) => (a.name || "").localeCompare(b.name || "", "de")),
);

const supportsDeviceIsActive = computed(() =>
  store.devices.some((device) => typeof activeValue(device) === "boolean"),
);

const supportsRoomIsActive = computed(() =>
  store.rooms.some((room) => typeof activeValue(room) === "boolean"),
);

const filteredDevices = computed<AdminDevice[]>(() => {
  const query = normalizedSearch.value;

  return store.devices.filter((device) => {
    const name = device.name?.toLowerCase() ?? "";
    const key = device.device_key?.toLowerCase() ?? "";
    const matchesSearch = !query || name.includes(query) || key.includes(query);
    if (!matchesSearch) return false;

    const resolvedRoomId = device.room_id ?? device.room?.id;
    if (roomFilter.value !== "all" && String(resolvedRoomId ?? "") !== roomFilter.value) {
      return false;
    }

    if (!supportsDeviceIsActive.value || activeFilter.value === "all") return true;

    const currentStatus = activeValue(device);
    if (activeFilter.value === "active") return currentStatus !== false;
    return currentStatus === false;
  });
});

function roomStatusClass(device: AdminDevice) {
  const roomActive = activeValue(device.room);
  if (typeof roomActive !== "boolean") return "neutral";
  return roomActive ? "active" : "inactive";
}

function roomStatusLabel(device: AdminDevice) {
  const roomActive = activeValue(device.room);
  if (typeof roomActive !== "boolean") return "Raum unbekannt";
  return roomActive ? "Raum aktiv" : "Raum inaktiv";
}

function deviceStatusClass(device: AdminDevice) {
  const deviceActive = activeValue(device);
  if (typeof deviceActive !== "boolean") return "neutral";
  return deviceActive ? "active" : "inactive";
}

function deviceStatusLabel(device: AdminDevice) {
  const deviceActive = activeValue(device);
  if (typeof deviceActive !== "boolean") return "Status offen";
  return deviceActive ? "Gerät aktiv" : "Gerät inaktiv";
}

function resetForm() {
  editingId.value = null;
  form.name = "";
  form.device_key = "";
  form.room_id = "";
  form.is_active = true;
}

function openEdit(device: AdminDevice) {
  editingId.value = device.id;
  form.name = device.name ?? "";
  form.device_key = device.device_key ?? "";
  form.room_id = String(device.room_id ?? device.room?.id ?? "");
  form.is_active = activeValue(device) ?? true;
}

function buildPayload() {
  const payload: Record<string, unknown> = {
    name: form.name.trim(),
    device_key: form.device_key.trim() || null,
    room_id: form.room_id ? Number(form.room_id) : null,
  };

  if (supportsDeviceIsActive.value || editingId.value) {
    payload.is_active = form.is_active;
  }

  return payload;
}

async function saveDevice() {
  if (!form.name.trim()) return;

  const payload = buildPayload();

  if (editingId.value) {
    await store.updateDevice(editingId.value, payload as Partial<AdminDevice>);
  } else {
    await store.createDevice(payload as Pick<AdminDevice, "name"> & Partial<AdminDevice>);
  }

  resetForm();
}

async function remove(device: AdminDevice) {
  if (!device.id) return;
  await store.deleteDevice(device.id);
  if (editingId.value === device.id) resetForm();
}

onMounted(async () => {
  await Promise.all([store.loadDevices(), store.loadRooms()]);
});
</script>

<template>
  <section class="admin-view">
    <header class="view-header">
      <div>
        <h2>Geräte</h2>
        <p class="muted">Geräte anlegen, bearbeiten und Räumen zuweisen.</p>
      </div>
    </header>

    <p v-if="store.error" class="error">{{ store.error }}</p>

    <section class="form-card">
      <h3>{{ editingId ? "Gerät bearbeiten" : "Neues Gerät" }}</h3>
      <form class="form-grid" @submit.prevent="saveDevice">
        <input v-model="form.name" type="text" class="input" placeholder="Name" required />
        <input v-model="form.device_key" type="text" class="input" placeholder="Device Key" />

        <select v-model="form.room_id" class="select" required>
          <option disabled value="">Raum wählen…</option>
          <option v-for="room in roomOptions" :key="room.id" :value="String(room.id)">
            {{ room.name }}
          </option>
        </select>

        <label v-if="supportsDeviceIsActive || editingId" class="checkbox">
          <input v-model="form.is_active" type="checkbox" />
          Gerät aktiv
        </label>

        <div class="form-actions">
          <button class="primary-btn" type="submit">
            {{ editingId ? "Speichern" : "Gerät erstellen" }}
          </button>
          <button v-if="editingId" type="button" class="secondary-btn" @click="resetForm">
            Abbrechen
          </button>
        </div>
      </form>
    </section>

    <div class="toolbar">
      <input
        v-model="searchTerm"
        type="search"
        class="input"
        placeholder="Gerät suchen…"
        aria-label="Gerät suchen"
      />

      <select v-model="roomFilter" class="select" aria-label="Nach Raum filtern">
        <option value="all">Alle Räume</option>
        <option v-for="room in roomOptions" :key="room.id" :value="String(room.id)">
          {{ room.name }}
        </option>
      </select>

      <select
        v-if="supportsDeviceIsActive"
        v-model="activeFilter"
        class="select"
        aria-label="Nach Gerätestatus filtern"
      >
        <option value="all">Alle Gerätestatus</option>
        <option value="active">Nur aktiv</option>
        <option value="inactive">Nur inaktiv</option>
      </select>
    </div>

    <div v-if="store.loading" class="empty-state">Lade Geräte…</div>

    <div v-else-if="!filteredDevices.length" class="empty-state">
      Keine Geräte gefunden. Passe Suche oder Filter an.
    </div>

    <ul v-else class="device-list">
      <li v-for="device in filteredDevices" :key="device.id" class="device-item">
        <div>
          <p class="title">{{ device.name }}</p>
          <p class="meta">Key: {{ device.device_key || "—" }}</p>
          <p class="meta">Raum: {{ device.room?.name || "Nicht zugeordnet" }}</p>
        </div>

        <div class="badges">
          <span class="badge" :class="deviceStatusClass(device)">{{ deviceStatusLabel(device) }}</span>
          <span v-if="supportsRoomIsActive" class="badge" :class="roomStatusClass(device)">
            {{ roomStatusLabel(device) }}
          </span>
        </div>

        <div class="actions">
          <button class="edit-btn" @click="openEdit(device)">Bearbeiten</button>
          <button class="delete-btn" @click="remove(device)">Löschen</button>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
@import '../styles/admin-shared.css';

.toolbar { grid-template-columns: minmax(180px, 1fr) repeat(2, minmax(160px, 220px)); }
.device-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
.device-item { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 10px; border: 1px solid #e6edf3; border-radius: 12px; padding: 12px; background: #fff; }
.badges { display: grid; gap: 6px; justify-items: end; }
@media (max-width: 960px) {
  .form-grid, .toolbar { grid-template-columns: 1fr; }
  .device-item { grid-template-columns: 1fr; align-items: start; }
  .badges { justify-items: start; }
}
</style>
