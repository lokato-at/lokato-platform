<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminDevice, AdminRoom } from "@/stores/adminDataStore";

type ActiveFilter = "all" | "active" | "inactive";

const store = useAdminDataStore();
const searchTerm = ref("");
const roomFilter = ref<string>("all");
const activeFilter = ref<ActiveFilter>("all");

const normalizedSearch = computed(() => searchTerm.value.trim().toLowerCase());

const roomOptions = computed<AdminRoom[]>(() =>
  store.rooms
    .slice()
    .sort((a, b) => (a.name || "").localeCompare(b.name || "", "de")),
);

const supportsIsActive = computed(() =>
  store.devices.some((device) => typeof device.room?.is_active === "boolean"),
);

const filteredDevices = computed<AdminDevice[]>(() => {
  const query = normalizedSearch.value;

  return store.devices.filter((device) => {
    const name = device.name?.toLowerCase() ?? "";
    const key = device.device_key?.toLowerCase() ?? "";
    const matchesSearch = !query || name.includes(query) || key.includes(query);

    if (!matchesSearch) return false;

    if (roomFilter.value !== "all" && String(device.room_id ?? "") !== roomFilter.value) {
      return false;
    }

    if (!supportsIsActive.value || activeFilter.value === "all") return true;

    const roomActive = device.room?.is_active;
    if (activeFilter.value === "active") return roomActive !== false;
    return roomActive === false;
  });
});

function roomStatusClass(device: AdminDevice) {
  const roomActive = device.room?.is_active;
  if (typeof roomActive !== "boolean") return "neutral";
  return roomActive ? "active" : "inactive";
}

function roomStatusLabel(device: AdminDevice) {
  const roomActive = device.room?.is_active;
  if (typeof roomActive !== "boolean") return "Status offen";
  return roomActive ? "Raum aktiv" : "Raum inaktiv";
}

async function remove(device: AdminDevice) {
  if (!device.id) return;
  await store.deleteDevice(device.id);
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
        <p class="muted">Suche nach Gerätename oder Device Key.</p>
      </div>
    </header>

    <p v-if="store.error" class="error">{{ store.error }}</p>

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
        v-if="supportsIsActive"
        v-model="activeFilter"
        class="select"
        aria-label="Nach Aktivität filtern"
      >
        <option value="all">Alle Status</option>
        <option value="active">Nur aktive Räume</option>
        <option value="inactive">Nur inaktive Räume</option>
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

        <span class="badge" :class="roomStatusClass(device)">{{ roomStatusLabel(device) }}</span>

        <button class="delete-btn" @click="remove(device)">Löschen</button>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.admin-view { display: grid; gap: 14px; }
.view-header { display: flex; justify-content: space-between; align-items: start; gap: 12px; }
.muted { margin: 4px 0 0; color: #64748b; font-size: 0.92rem; }
.toolbar { display: grid; gap: 10px; grid-template-columns: minmax(180px, 1fr) repeat(2, minmax(160px, 220px)); }
.input, .select { border: 1px solid #dbe2ea; border-radius: 10px; padding: 9px 11px; background: #fff; }
.device-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
.device-item { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 10px; border: 1px solid #e6edf3; border-radius: 12px; padding: 12px; background: #fff; }
.title { margin: 0; font-weight: 600; }
.meta { margin: 3px 0 0; color: #64748b; font-size: 0.9rem; }
.badge { border-radius: 999px; padding: 5px 9px; font-size: 0.78rem; font-weight: 600; }
.badge.active { background: #dcfce7; color: #166534; }
.badge.inactive { background: #fee2e2; color: #991b1b; }
.badge.neutral { background: #f1f5f9; color: #334155; }
.delete-btn { border: 1px solid #ef4444; color: #b91c1c; background: #fff; border-radius: 9px; padding: 7px 10px; cursor: pointer; }
.delete-btn:hover { background: #fef2f2; }
.empty-state { border: 1px dashed #dbe2ea; border-radius: 12px; padding: 16px; color: #64748b; text-align: center; }
.error { color: #b91c1c; margin: 0; }
@media (max-width: 860px) {
  .toolbar { grid-template-columns: 1fr; }
  .device-item { grid-template-columns: 1fr; align-items: start; }
}
</style>
