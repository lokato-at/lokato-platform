<template>
  <div class="dashboard">

    <!-- HEADER -->
    <header>
      <h1>Admin Dashboard</h1>
      <p class="subtitle">Live-Übersicht aller Räume & Kinder</p>
    </header>

    <!-- SUCHE -->
    <div class="search-row">
      <input v-model="search" placeholder="🔍 Kind suchen…" />
      <input v-model="searchRoom" placeholder="🏠 Raum suchen…" />
    </div>

    <!-- STATUS -->
    <p v-if="store.loading" class="loading">⏳ Lade…</p>
    <p v-if="store.error" class="error">{{ store.error }}</p>

    <!-- ========================================================= -->
    <!-- RÄUME -->
    <!-- ========================================================= -->
    <section>
      <h2>Räume</h2>

      <div class="rooms">
        <div v-for="room in filteredRooms" :key="room.id" class="room">

          <!-- Raumkopf -->
          <div class="room-header">
            <h3>{{ room.name }}</h3>
            <button class="btn small" @click="openRoomEdit(room)">✏ Edit Room</button>
          </div>

          <!-- Raumkapazität -->
          <p class="capacity">{{ room.current_count }} / {{ room.capacity }}</p>

          <!-- Raumstatus -->
          <p
            class="status"
            :class="{
              red: room.status.over_capacity,
              yellow: room.status.within_tolerance && !room.status.over_capacity,
              green: !room.status.within_tolerance && !room.status.over_capacity
            }"
          >
            {{
              room.status.over_capacity
                ? "Überfüllt"
                : room.status.within_tolerance ? "Toleranzbereich" : "OK"
            }}
          </p>

          <h4>Kinder</h4>

          <!-- KINDERLISTE -->
          <ul class="children">

            <li
              v-for="child in filteredOccupancy[String(room.id)]?.children || []"
              :key="child.child_id || child.id"
              class="child"
            >
              <img
                :src="child.photo_url || 'https://via.placeholder.com/40?text=?'"
                class="child-photo"
              />

              <span class="child-name">{{ child.name }}</span>

              <!-- Edit -->
              <button class="btn-xs" @click="openChildEdit(child)">✏</button>

              <!-- Move -->
              <button class="btn-xs" @click="openChildMove(child)">↦</button>

              <!-- TODO: Kinder Movement hat noch Fehler (Findet keine Tracker UID)

                    TODO: Findet generell zu wenige Daten also die Seite an sich ist noch in Testphase
               -->

            </li>

            <li
              v-if="!(filteredOccupancy[String(room.id)]?.children || []).length"
              class="muted"
            >
              Keine Kinder in diesem Raum.
            </li>
          </ul>
        </div>
      </div>
    </section>

    <!-- ========================================================= -->
    <!-- LETZTE BEWEGUNGEN -->
    <!-- ========================================================= -->
    <section>
      <h2>Letzte Bewegungen</h2>

      <ul class="movements">
        <li v-for="m in store.latestMovements" :key="m.id">
          <strong>{{ m.child?.name ?? "?" }}</strong>
          →
          <strong>{{ m.to_room?.name ?? "?" }}</strong>
          <span class="time">({{ formatDate(m.occurred_at) }})</span>
        </li>

        <li v-if="store.latestMovements.length === 0" class="muted">
          Keine Bewegungen vorhanden.
        </li>
      </ul>
    </section>

    <!-- ========================================================= -->
    <!-- MODAL: ROOM EDIT -->
    <!-- ========================================================= -->
    <div v-if="editingRoom !== null" class="modal">
      <div class="modal-box">
        <h3>Raum bearbeiten</h3>

        <label>Name<input v-model="roomForm.name" /></label>
        <label>Bereich<input v-model="roomForm.area" /></label>
        <label>Kapazität<input type="number" v-model.number="roomForm.capacity" /></label>
        <label>Toleranz<input type="number" v-model.number="roomForm.tolerance" /></label>

        <label><input type="checkbox" v-model="roomForm.is_active" /> Aktiv</label>

        <div class="modal-actions">
          <button class="btn primary" @click="saveRoomEdit">Speichern</button>
          <button class="btn" @click="editingRoom = null">Abbrechen</button>
        </div>
      </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL: CHILD EDIT -->
    <!-- ========================================================= -->
    <div v-if="editingChild !== null" class="modal">
      <div class="modal-box">
        <h3>Kind bearbeiten</h3>

        <label>Name<input v-model="childForm.name" /></label>
        <label>Foto URL<input v-model="childForm.photo_url" /></label>
        <label>Tracker UID<input v-model="childForm.tracker_uid" /></label>
        <label><input type="checkbox" v-model="childForm.is_active" /> Aktiv</label>

        <div class="modal-actions">
          <button class="btn primary" @click="saveChildEdit">Speichern</button>
          <button class="btn" @click="editingChild = null">Abbrechen</button>
        </div>
      </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL: CHILD MOVE (SCAN SIMULATOR) -->
    <!-- ========================================================= -->
    <div v-if="movingChild !== null" class="modal">
      <div class="modal-box">
        <h3>Movement erstellen</h3>

        <p><strong>{{ movingChild?.name }}</strong> bewegen</p>

        <label>
          Gerät auswählen
          <select v-model="moveDeviceKey">
            <option
              v-for="d in admin.devices"
              :key="d.id"
              :value="d.device_key"
            >
              {{ d.name }} (Room #{{ d.room_id }})
            </option>
          </select>
        </label>

        <label>
          Zeitpunkt (optional)
          <input type="datetime-local" v-model="moveEventTime" />
        </label>

        <div class="modal-actions">
          <button class="btn primary" @click="performMove">Bewegen</button>
          <button class="btn" @click="movingChild = null">Abbrechen</button>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup lang="ts">
/* Imports */
import {
  ref,
  reactive,
  computed,
  onMounted,
  onUnmounted
} from "vue";

import { useDashboardDataStore } from "@/stores/dashboardDataStore";
import { useAdminDataStore } from "@/stores/adminDataStore";

/* Stores */
const store = useDashboardDataStore();
const admin = useAdminDataStore();

/* Auto-refresh */
let refreshInterval: number | null = null;

onMounted(async () => {
  await store.fetchAllDashboardData();
  admin.loadDevices();

  refreshInterval = window.setInterval(() => {
    store.fetchAllDashboardData();
  }, 30000);
});

onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval);
});

/* Suche */
const search = ref("");
const searchRoom = ref("");

/* Kinder pro Raum filtern */
const filteredOccupancy = computed(() => {
  if (!search.value) return store.occupancy || {};

  const q = search.value.toLowerCase();
  const result: Record<string, any> = {};

  for (const [roomId, data] of Object.entries(store.occupancy || {})) {
    const filtered = (data.children || []).filter((c: any) =>
      c.name?.toLowerCase().includes(q) ||
      c.tracker_uid?.toLowerCase().includes(q)
    );

    result[String(roomId)] = { ...data, children: filtered };
  }

  return result;
});

/* Räume filtern */
const filteredRooms = computed(() => {
  if (!searchRoom.value) return store.rooms || [];
  const q = searchRoom.value.toLowerCase();
  return store.rooms.filter((r) => r.name.toLowerCase().includes(q));
});

/* Datum formatieren */
const formatDate = (iso?: string) =>
  iso
    ? new Date(iso).toLocaleString("de-DE", {
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit"
    })
    : "";

/* ========================================================= */
/* ROOM EDIT */
/* ========================================================= */
const editingRoom = ref<number | null>(null);

const roomForm = reactive({
  name: "",
  area: "",
  capacity: 0,
  tolerance: 0,
  is_active: true
});

function openRoomEdit(room: any) {
  editingRoom.value = Number(room.id);
  roomForm.name = room.name;
  roomForm.area = room.area;
  roomForm.capacity = room.capacity;
  roomForm.tolerance = room.tolerance;
  roomForm.is_active = room.is_active;
}

async function saveRoomEdit() {
  await admin.updateRoom(editingRoom.value!, { ...roomForm });
  editingRoom.value = null;
  await store.fetchAllDashboardData();
}

/* ========================================================= */
/* CHILD EDIT */
/* ========================================================= */
const editingChild = ref<number | null>(null);

const childForm = reactive({
  name: "",
  photo_url: "",
  tracker_uid: "",
  is_active: true
});

function openChildEdit(child: any) {
  editingChild.value = Number(child.id ?? child.child_id);

  childForm.name = child.name ?? "";
  childForm.photo_url = child.photo_url ?? "";
  childForm.tracker_uid = child.tracker_uid ?? "";
  childForm.is_active = child.is_active ?? true;
}

async function saveChildEdit() {
  await admin.updateChild(editingChild.value!, { ...childForm });
  editingChild.value = null;
  await store.fetchAllDashboardData();
}

/* ========================================================= */
/* CHILD MOVE (SCAN SIMULATION) */
/* ========================================================= */
const movingChild = ref<any>(null);
const moveDeviceKey = ref<string>("");
const moveEventTime = ref<string>("");

function openChildMove(child: any) {
  movingChild.value = child;

  if (!admin.devices.length) {
    admin.loadDevices();
  }
}

async function performMove() {
  if (!movingChild.value) return;

  const child = movingChild.value;

  /* Universal Tracker-Resolver */
  const tracker =
    child.tracker_uid ??
    admin.children.find((c) => c.id == child.id)?.tracker_uid ??
    admin.children.find((c) => c.id == child.child_id)?.tracker_uid ??
    null;

  if (!tracker) {
    alert("Dieses Kind hat keinen tracker_uid!");
    return;
  }

  if (!moveDeviceKey.value) {
    alert("Bitte ein Gerät auswählen!");
    return;
  }

  await admin.sendScanEvent({
    device_key: moveDeviceKey.value,
    tracker_uid: tracker,
    event_time: moveEventTime.value || undefined
  });

  movingChild.value = null;

  /* Reload Dashboard */
  await store.fetchAllDashboardData();
}
</script>

<style scoped>
.dashboard {
  max-width: 1100px;
  margin: auto;
  padding: 32px;
  font-family: system-ui, sans-serif;
}

.subtitle {
  color: #777;
  margin-bottom: 20px;
}

.search-row {
  display: flex;
  gap: 12px;
  margin-bottom: 20px;
}

.search-row input {
  flex: 1;
  padding: 10px;
  border: 1px solid #ccc;
  border-radius: 8px;
}

.rooms {
  display: grid;
  gap: 20px;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
}

.room {
  padding: 18px;
  background: white;
  border-radius: 12px;
  border: 1px solid #ddd;
}

.room-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.status.green { color: #009900; }
.status.yellow { color: #d29c00; }
.status.red { color: #d60000; }

.children {
  list-style: none;
  padding: 0;
}

.child {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 0;
}

.child-photo {
  width: 42px;
  height: 42px;
  border-radius: 8px;
  object-fit: cover;
}

.child-name {
  flex: 1;
}

.btn-xs {
  padding: 4px 8px;
  background: #ececec;
  border-radius: 6px;
  cursor: pointer;
  border: none;
  font-size: 12px;
}

.btn {
  padding: 8px 12px;
  border-radius: 6px;
  cursor: pointer;
  background: #eee;
  border: none;
}

.btn.primary {
  background: #2d7bff !important;
  color: white;
}

.btn.small {
  padding: 6px 10px;
}

.modal {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.4);
  display: flex;
  justify-content: center;
  align-items: center;
}

.modal-box {
  width: 350px;
  padding: 24px;
  background: white;
  border-radius: 12px;
}

.modal-box input,
.modal-box select {
  width: 100%;
  padding: 8px;
  border: 1px solid #ccc;
  border-radius: 6px;
  margin-top: 4px;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 12px;
}

.muted {
  color: #777;
}

.error {
  color: red;
}

.loading {
  color: #555;
}
</style>
