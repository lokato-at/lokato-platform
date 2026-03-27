<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminChild, AdminDevice, AdminRoom } from "@/stores/adminDataStore";

const store = useAdminDataStore();
const childSearch = ref("");
const targetSearch = ref("");
const selectedRoom = ref<string>("all");

const normalizedChildSearch = computed(() => childSearch.value.trim().toLowerCase());
const normalizedTargetSearch = computed(() => targetSearch.value.trim().toLowerCase());

const visibleChildren = computed<AdminChild[]>(() => {
  const query = normalizedChildSearch.value;
  return store.children.filter((child) => {
    if (typeof child.is_active === "boolean" && !child.is_active) return false;

    const name = child.name?.toLowerCase() ?? "";
    const tracker = child.tracker_uid?.toLowerCase() ?? "";
    return !query || name.includes(query) || tracker.includes(query);
  });
});

const roomById = computed<Map<number, AdminRoom>>(() => {
  const map = new Map<number, AdminRoom>();
  for (const room of store.rooms) map.set(room.id, room);
  return map;
});

interface DeviceGroup {
  roomId: number;
  roomName: string;
  roomArea: string;
  roomActiveKnown: boolean;
  roomActive: boolean;
  devices: AdminDevice[];
}

const groupedDevices = computed<DeviceGroup[]>(() => {
  const target = normalizedTargetSearch.value;
  const groups = new Map<number, DeviceGroup>();

  for (const device of store.devices) {
    const room = (device.room_id ? roomById.value.get(device.room_id) : null) ?? device.room ?? null;
    if (room && typeof room.is_active === "boolean" && !room.is_active) continue;

    const roomId = room?.id ?? -1;
    if (selectedRoom.value !== "all" && String(roomId) !== selectedRoom.value) continue;

    const deviceName = device.name?.toLowerCase() ?? "";
    const deviceKey = device.device_key?.toLowerCase() ?? "";
    const roomName = room?.name ?? "Nicht zugeordnet";
    const roomNameQuery = roomName.toLowerCase();
    if (target && !deviceName.includes(target) && !deviceKey.includes(target) && !roomNameQuery.includes(target)) {
      continue;
    }

    const group = groups.get(roomId) ?? {
      roomId,
      roomName,
      roomArea: room?.area ?? "",
      roomActiveKnown: typeof room?.is_active === "boolean",
      roomActive: room?.is_active !== false,
      devices: [],
    };

    group.devices.push(device);
    groups.set(roomId, group);
  }

  return Array.from(groups.values()).sort((a, b) => a.roomName.localeCompare(b.roomName, "de"));
});

const roomFilterOptions = computed(() =>
  store.rooms
    .filter((room) => !(typeof room.is_active === "boolean" && !room.is_active))
    .map((room) => ({ id: String(room.id), name: room.name }))
    .sort((a, b) => a.name.localeCompare(b.name, "de")),
);

async function send(device: AdminDevice, child: AdminChild) {
  if (!device.device_key || !child.tracker_uid) return;
  await store.sendScanEvent({
    device_key: device.device_key,
    tracker_uid: child.tracker_uid,
  });
}

onMounted(async () => {
  await Promise.all([store.loadChildren(), store.loadDevices(), store.loadRooms()]);
});
</script>

<template>
  <section class="movement-view">
    <header class="header">
      <div>
        <h2>Movement Simulator</h2>
        <p class="muted">Wähle ein Gerät und simuliere Bewegungen mit aktiven Kindern.</p>
      </div>
    </header>

    <p v-if="store.error" class="error">{{ store.error }}</p>

    <div class="toolbar">
      <input
        v-model="childSearch"
        type="search"
        class="input"
        placeholder="Kinder suchen…"
        aria-label="Kinder suchen"
      />
      <input
        v-model="targetSearch"
        type="search"
        class="input"
        placeholder="Räume oder Geräte suchen…"
        aria-label="Räume oder Geräte suchen"
      />
      <select v-model="selectedRoom" class="select" aria-label="Nach Raum filtern">
        <option value="all">Alle Raumgruppen</option>
        <option v-for="room in roomFilterOptions" :key="room.id" :value="room.id">
          {{ room.name }}
        </option>
      </select>
    </div>

    <div v-if="store.loading" class="empty-state">Lade Daten…</div>

    <div v-else-if="!visibleChildren.length" class="empty-state">
      Keine passenden, aktiven Kinder gefunden.
    </div>

    <div v-else-if="!groupedDevices.length" class="empty-state">
      Keine passenden Geräte oder Räume gefunden.
    </div>

    <div v-else class="groups">
      <article v-for="group in groupedDevices" :key="group.roomId" class="group-card">
        <header class="group-header">
          <div>
            <h3>{{ group.roomName }}</h3>
            <p v-if="group.roomArea" class="muted">{{ group.roomArea }}</p>
          </div>
          <span v-if="group.roomActiveKnown" class="badge" :class="group.roomActive ? 'active' : 'inactive'">
            {{ group.roomActive ? "Raum aktiv" : "Raum inaktiv" }}
          </span>
        </header>

        <div class="device-grid">
          <section v-for="device in group.devices" :key="device.id" class="device-card">
            <p class="device-name">{{ device.name }}</p>
            <p class="device-meta">Key: {{ device.device_key || "nicht gesetzt" }}</p>

            <div class="child-actions">
              <button
                v-for="child in visibleChildren"
                :key="`${device.id}-${child.id}`"
                class="child-btn"
                :disabled="!device.device_key || !child.tracker_uid"
                @click="send(device, child)"
              >
                {{ child.name }}
              </button>
            </div>
          </section>
        </div>
      </article>
    </div>
  </section>
</template>

<style scoped>
.movement-view { display: grid; gap: 14px; }
.header { display: flex; justify-content: space-between; align-items: start; gap: 12px; }
.muted { margin: 4px 0 0; color: #64748b; font-size: 0.9rem; }
.toolbar { display: grid; gap: 10px; grid-template-columns: repeat(3, minmax(160px, 1fr)); }
.input, .select { border: 1px solid #dbe2ea; border-radius: 10px; padding: 9px 11px; background: #fff; }
.groups { display: grid; gap: 12px; }
.group-card { border: 1px solid #e6edf3; border-radius: 12px; padding: 12px; background: #fff; display: grid; gap: 10px; }
.group-header { display: flex; justify-content: space-between; gap: 10px; align-items: start; }
.group-header h3 { margin: 0; }
.device-grid { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
.device-card { border: 1px solid #edf2f7; border-radius: 10px; padding: 10px; display: grid; gap: 8px; }
.device-name { margin: 0; font-weight: 600; }
.device-meta { margin: 0; color: #64748b; font-size: 0.86rem; }
.child-actions { display: flex; flex-wrap: wrap; gap: 6px; }
.child-btn { border: 1px solid #d6e0ff; background: #f8faff; color: #1e40af; border-radius: 8px; padding: 6px 9px; cursor: pointer; font-size: 0.84rem; }
.child-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.badge { border-radius: 999px; padding: 5px 9px; font-size: 0.78rem; font-weight: 600; }
.badge.active { background: #dcfce7; color: #166534; }
.badge.inactive { background: #fee2e2; color: #991b1b; }
.empty-state { border: 1px dashed #dbe2ea; border-radius: 12px; padding: 16px; color: #64748b; text-align: center; }
.error { color: #b91c1c; margin: 0; }
@media (max-width: 900px) {
  .toolbar { grid-template-columns: 1fr; }
}
</style>
