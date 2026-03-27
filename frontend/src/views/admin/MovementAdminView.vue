<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminChild, AdminDevice, AdminRoom } from "@/stores/adminDataStore";

const store = useAdminDataStore();
const childSearch = ref("");
const targetSearch = ref("");
const selectedRoom = ref<string>("all");
const selectedChildId = ref<string>("");
const selectedDeviceKey = ref<string>("");
const eventTime = ref("");

const normalizedChildSearch = computed(() => childSearch.value.trim().toLowerCase());
const normalizedTargetSearch = computed(() => targetSearch.value.trim().toLowerCase());

function activeValue(entity: unknown): boolean | undefined {
  if (!entity || typeof entity !== "object") return undefined;
  const record = entity as Record<string, unknown>;
  if (typeof record.is_active === "boolean") return record.is_active;
  if (typeof record.isActive === "boolean") return record.isActive;
  return undefined;
}

const visibleChildren = computed<AdminChild[]>(() => {
  const query = normalizedChildSearch.value;
  return store.children.filter((child) => {
    const isActive = activeValue(child);
    if (isActive === false) return false;

    const name = child.name?.toLowerCase() ?? "";
    const tracker = child.tracker_uid?.toLowerCase() ?? "";
    return !query || name.includes(query) || tracker.includes(query);
  });
});

const selectedChild = computed(() =>
  visibleChildren.value.find((child) => String(child.id) === selectedChildId.value) ?? null,
);

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
    const deviceIsActive = activeValue(device);
    if (deviceIsActive === false) continue;

    const room = (device.room_id ? roomById.value.get(device.room_id) : null) ?? device.room ?? null;
    const roomIsActive = activeValue(room);
    if (roomIsActive === false) continue;

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
      roomActiveKnown: typeof roomIsActive === "boolean",
      roomActive: roomIsActive === true,
      devices: [],
    };

    group.devices.push(device);
    groups.set(roomId, group);
  }

  return Array.from(groups.values()).sort((a, b) => a.roomName.localeCompare(b.roomName, "de"));
});

const roomFilterOptions = computed(() =>
  store.rooms
    .filter((room) => activeValue(room) !== false)
    .map((room) => ({ id: String(room.id), name: room.name }))
    .sort((a, b) => a.name.localeCompare(b.name, "de")),
);

function selectDevice(device: AdminDevice) {
  selectedDeviceKey.value = device.device_key ?? "";
}

async function sendScan(deviceKey: string, child: AdminChild | null) {
  if (!deviceKey || !child?.tracker_uid) return;

  await store.sendScanEvent({
    device_key: deviceKey,
    tracker_uid: child.tracker_uid,
    event_time: eventTime.value || undefined,
  });
}

async function sendFromForm() {
  await sendScan(selectedDeviceKey.value, selectedChild.value);
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
        <p class="muted">Aktives Kind wählen und Scans pro Gerät schnell auslösen.</p>
      </div>
    </header>

    <p v-if="store.error" class="error">{{ store.error }}</p>

    <section class="panel">
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

      <h4 style="margin-bottom: 0;">Scan Simulieren:</h4>
      <div class="toolbar compact">
        <select v-model="selectedChildId" class="select" aria-label="Kind auswählen">
          <option value="">Kind wählen…</option>
          <option v-for="child in visibleChildren" :key="child.id" :value="String(child.id)">
            {{ child.name }}
          </option>
        </select>

        <input v-model="selectedDeviceKey" type="text" class="input" placeholder="Device Key" />
        <input v-model="eventTime" type="datetime-local" class="input" aria-label="Zeitpunkt" />
        <button class="primary-btn" :disabled="!selectedDeviceKey || !selectedChild?.tracker_uid" @click="sendFromForm">
          Scan senden
        </button>
      </div>
    </section>

    <div v-if="store.loading" class="empty-state">Lade Daten…</div>
    <div v-else-if="!visibleChildren.length" class="empty-state">Keine passenden, aktiven Kinder gefunden.</div>
    <div v-else-if="!groupedDevices.length" class="empty-state">Keine passenden Geräte oder Räume gefunden.</div>

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
            <div class="device-actions">
              <button class="secondary-btn" @click="selectDevice(device)">Key übernehmen</button>
              <button
                class="primary-btn"
                :disabled="!device.device_key || !selectedChild?.tracker_uid"
                @click="sendScan(device.device_key || '', selectedChild)"
              >
                Mit ausgewähltem Kind scannen
              </button>
            </div>
          </section>
        </div>
      </article>
    </div>

    <section class="panel">
      <h3>Letztes Scan-Ergebnis</h3>
      <pre v-if="store.lastScanResult" class="result-box">{{ JSON.stringify(store.lastScanResult, null, 2) }}</pre>
      <p v-else class="muted">Noch kein Scan ausgelöst.</p>
    </section>
  </section>
</template>

<style scoped>
@import '../styles/admin-shared.css';

.toolbar { grid-template-columns: repeat(3, minmax(160px, 1fr)); }
.toolbar.compact { grid-template-columns: repeat(4, minmax(140px, 1fr)); }
.groups { display: grid; gap: 12px; }
.group-header { display: flex; justify-content: space-between; gap: 10px; align-items: start; }
.device-grid { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
.device-card { border: 1px solid #edf2f7; border-radius: 10px; padding: 10px; display: grid; gap: 8px; }
.device-name { margin: 0; font-weight: 600; }
.device-meta { margin: 0; color: #64748b; font-size: 0.86rem; }
.primary-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.result-box { margin: 0; background: #0f172a; color: #cbd5e1; border-radius: 10px; padding: 12px; overflow: auto; font-size: 0.82rem; }
@media (max-width: 980px) {
  .toolbar, .toolbar.compact { grid-template-columns: 1fr; }
}
</style>
