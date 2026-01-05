<template>
  <div class="dashboard-view">
    <!-- --------------------------------------------------------
         TITELBEREICH
         -------------------------------------------------------- -->
    <h1>Dashboard</h1>

    <p class="desc">
      Öffentliche Live-Übersicht aller Räume und der Kinder in jedem Raum.
      Die Daten stammen aus der öffentlichen API (GET /rooms, GET /rooms/{id}/occupancy, GET /movement-log).
    </p>

    <!-- --------------------------------------------------------
         SUCHFELDER
         -------------------------------------------------------- -->
    <div class="search-bar">
      <input
        v-model="search"
        type="text"
        placeholder="🔍 Kind suchen…"
      />
      <button v-if="search" class="clear-btn" @click="search = ''">✖</button>
    </div>

    <div class="search-bar">
      <input
        v-model="searchRoom"
        type="text"
        placeholder="🏠 Raum suchen…"
      />
      <button v-if="searchRoom" class="clear-btn" @click="searchRoom = ''">✖</button>
    </div>

    <!-- Ladeindikator -->
    <div v-if="store.loading">⏳ Lade Daten…</div>

    <!-- Fehleranzeige -->
    <div v-if="store.error" class="error-box">
      ❌ {{ store.error }}
    </div>

    <!-- ========================================================= -->
    <!-- 1) RÄUME MIT BELEGUNG                                     -->
    <!-- ========================================================= -->
    <section>
      <h2>Räume</h2>

      <div class="rooms-grid">
        <article
          class="room-card"
          v-for="room in filteredRooms"
          :key="room.id"
        >
          <h3>{{ room.name }}</h3>

          <p class="room-capacity">
            Kapazität:
            <strong>{{ room.current_count }} / {{ room.capacity }}</strong>
          </p>

          <p class="room-status">
            Status:
            <strong
              :class="{
                red: room.status.over_capacity,
                yellow: !room.status.over_capacity && room.status.within_tolerance,
                green: !room.status.over_capacity && !room.status.within_tolerance,
              }"
            >
              {{
                room.status.over_capacity
                  ? "Überfüllt"
                  : room.status.within_tolerance
                    ? "Toleranzbereich"
                    : "OK"
              }}
            </strong>
          </p>

          <h4>Kinder</h4>

          <!-- 🔥 SORTIERUNG TOGGLE -->
          <div class="sort-toggle">
            <button
              @click="sortOrderByRoom[room.id] =
                sortOrderByRoom[room.id] === 'asc' ? 'desc' : 'asc'"
            >
              Sortierung:
              {{ sortOrderByRoom[room.id] === 'asc'
              ? "Erster oben"
              : "Letzter oben"
              }}
            </button>
          </div>

          <ul
            v-if="filteredOccupancy[room.id]?.children.length"
            class="child-list"
          >

            <!-- 🔥 KINDERLISTE SORTIERT -->
            <li
              v-for="child in sortChildrenForRoom(room.id, filteredOccupancy[room.id].children)"
              :key="child.child_id"
              class="child-entry"
            >
              <img
                v-if="child.photo_url"
                :src="child.photo_url"
                alt="Foto"
                class="child-photo"
              />

              <img
                v-else
                src="https://via.placeholder.com/40?text=?"
                alt="? "
                class="child-photo"
              />

              <span>
                {{ child.name }}
                <em style="font-size: small; opacity:0.6;">im Raum seit</em>

                <small
                  class="time-diff"
                  :class="getDurationColor(child.child_id)"
                >
                  {{ timeInRoomByChild[child.child_id] || "" }}
                </small>
              </span>
            </li>
          </ul>

          <p v-else class="muted">Keine Treffer für diesen Raum.</p>
        </article>
      </div>
    </section>

    <!-- ========================================================= -->
    <!-- 2) LETZTE BEWEGUNGEN AUS DEM MOVEMENT LOG                -->
    <!-- ========================================================= -->
    <section class="movements-section">
      <h2>Letzte Bewegungen</h2>

      <ol class="movement-list">
        <li v-for="m in store.latestMovements" :key="m.id">
          <strong>{{ m.child?.name ?? "?" }}</strong>
          <span class="arrow">→</span>
          <strong>{{ m.to_room?.name ?? "?" }}</strong>
          <span class="movement-time">({{ formatDate(m.occurred_at) }})</span>
        </li>
      </ol>

      <p v-if="store.latestMovements.length === 0" class="muted">
        Keine Bewegungen vorhanden.
      </p>
    </section>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from "vue";
import { useDashboardDataStore } from "@/stores/dashboardDataStore";

const store = useDashboardDataStore();

/* ==========================================================
   INITIAL LOAD + SSE
   ========================================================== */
onMounted(async () => {
  await store.fetchAllDashboardData(); // einmaliger REST-Snapshot
  store.connectSSE();                  // 🔴 Live-Updates via SSE
});

onUnmounted(() => {
  store.disconnectSSE();               // SSE sauber trennen
});

/* ==========================================================
   SUCHFELDER
   ========================================================== */
const search = ref("");
const searchRoom = ref("");

/* ==========================================================
   LIVE-ZEITUPDATER (jede Sekunde)
   ========================================================== */
const now = ref(Date.now());
setInterval(() => {
  now.value = Date.now();
}, 1000);

/* ==========================================================
   ZEIT PRO KIND (HH:MM:SS)
   ========================================================== */
const timeInRoomByChild = computed(() => {
  const result: Record<number, string> = {};
  const movements = store.latestMovements;

  if (!movements || !Array.isArray(movements)) return result;

  const current = now.value;
  const byChild = new Map<number, unknown>();

  movements.forEach((m: unknown) => {
    const childId = Number(m.child?.id);
    if (!childId || !m.occurred_at) return;

    if (!byChild.has(childId)) byChild.set(childId, m);
  });

  byChild.forEach((m, childId) => {
    const t = new Date(m.occurred_at).getTime();
    const diffMs = current - t;

    const totalSec = Math.floor(diffMs / 1000);
    const h = Math.floor(totalSec / 3600);
    const min = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;

    const hh = String(h).padStart(2, "0");
    const mm = String(min).padStart(2, "0");
    const ss = String(s).padStart(2, "0");

    result[childId] = `${hh}:${mm}:${ss}`;
  });

  return result;
});

/* ==========================================================
   FARBKLASSEN
   ========================================================== */
function getDurationColor(childId: number) {
  const txt = timeInRoomByChild.value?.[childId];
  if (!txt) return "";

  const [h, m] = txt.split(":").map(Number);
  const totalMin = h * 60 + m;

  if (totalMin < 10) return "green-time";
  if (totalMin < 30) return "yellow-time";
  return "red-time";
}

/* ==========================================================
   SORTIERUNG NACH EINTRITTSZEIT (pro Raum)
   ========================================================== */
const sortOrderByRoom = ref<Record<number, "asc" | "desc">>({});

function sortChildrenForRoom(roomId: number, children: unknown[]) {
  const order = sortOrderByRoom.value[roomId] || "desc";

  function getEntryTimestamp(child: unknown) {
    const move = store.latestMovements.find(
      m => m.child?.id === child.child_id
    );
    return move ? new Date(move.occurred_at).getTime() : 0;
  }

  return [...children].sort((a, b) => {
    const tA = getEntryTimestamp(a);
    const tB = getEntryTimestamp(b);
    return order === "asc" ? tA - tB : tB - tA;
  });
}

/* ==========================================================
   FILTER KINDER
   ========================================================== */
const filteredOccupancy = computed(() => {
  const occ = store.occupancy || {};
  if (!search.value) return occ;

  const q = search.value.toLowerCase();
  const result: Record<number, unknown> = {};

  for (const [roomId, data] of Object.entries(occ)) {
    const children = (data as unknown).children || [];

    const filtered = children.filter((c: unknown) =>
      (c.name ?? "").toLowerCase().includes(q)
    );

    result[Number(roomId)] = {
      ...(data as unknown),
      children: filtered
    };
  }

  return result;
});

/* ==========================================================
   FILTER RÄUME
   ========================================================== */
const filteredRooms = computed(() => {
  const rooms = store.rooms || [];
  if (!searchRoom.value) return rooms;

  const q = searchRoom.value.toLowerCase();
  return rooms.filter((r: unknown) =>
    (r.name ?? "").toLowerCase().includes(q)
  );
});

/* ==========================================================
   DATUM FORMATIEREN
   ========================================================== */
const formatDate = (iso?: string) => {
  if (!iso) return "";
  return new Date(iso).toLocaleString("de-DE", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
};
</script>


<style scoped>
/* ==========================================================
   GLOBALER LOOK
   ========================================================== */
.dashboard-view {
  padding: 32px;
  max-width: 1150px;
  margin: auto;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
}

h1 {
  margin-bottom: 6px;
  font-size: 2rem;
  font-weight: 700;
}

.desc {
  color: #666;
  margin-bottom: 24px;
  font-size: 0.95rem;
}

/* ==========================================================
   SUCHLEISTEN
   ========================================================== */
.search-bar {
  display: flex;
  justify-content: center;
  margin: 16px 0 26px 0;
  position: relative;
}

.search-bar input {
  width: 100%;
  max-width: 520px;
  padding: 11px 16px;
  border-radius: 14px;
  border: 1px solid #ddd;
  background: #fafafa;
  font-size: 15px;
  transition: all 0.2s ease;
}

.search-bar input:focus {
  outline: none;
  border-color: #3b82f6;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(59,130,246,0.25);
}

.clear-btn {
  position: absolute;
  right: calc(50% - 260px);
  top: 50%;
  transform: translateY(-50%);
  border: none;
  background: none;
  font-size: 16px;
  cursor: pointer;
  color: #888;
}

.clear-btn:hover {
  color: #333;
}

/* ==========================================================
   RAUMKARTEN GRID
   ========================================================== */
.rooms-grid {
  display: grid;
  gap: 26px;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
}

/* ==========================================================
   EINZELNE RAUMKARTE
   ========================================================== */
.room-card {
  background: #fff;
  border-radius: 14px;
  border: 1px solid #e5e7eb;
  padding: 22px;
  box-shadow:
    0 2px 6px rgba(0,0,0,0.03),
    0 8px 20px rgba(0,0,0,0.04);
  transition: transform 0.12s ease, box-shadow 0.2s ease;
}

.room-card:hover {
  transform: translateY(-4px);
  box-shadow:
    0 4px 12px rgba(0,0,0,0.06),
    0 12px 28px rgba(0,0,0,0.05);
}

.room-card h3 {
  margin-bottom: 6px;
  font-size: 1.2rem;
}

.room-capacity strong {
  font-weight: 600;
}

.room-status strong.green { color: #16a34a; }
.room-status strong.yellow { color: #ca8a04; }
.room-status strong.red   { color: #dc2626; }

/* ==========================================================
   SORTIER-BUTTON
   ========================================================== */
.sort-toggle {
  margin: 8px 0 14px 0;
}

.sort-toggle button {
  border: 1px solid #d1d5db;
  background: #f3f4f6;
  padding: 6px 12px;
  font-size: 12px;
  border-radius: 8px;
  cursor: pointer;
  opacity: 0.9;
  transition: all 0.15s ease;
}

.sort-toggle button:hover {
  background: #e5e7eb;
  opacity: 1;
}

/* ==========================================================
   KINDER LISTE
   ========================================================== */
.child-list {
  list-style: none;
  padding-left: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.child-entry {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 0;
  border-bottom: 1px solid #f1f1f1;
}

.child-entry:last-child {
  border-bottom: none;
}

.child-photo {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  object-fit: cover;
  border: 1px solid #e5e7eb;
  background: #f9fafb;
}

/* Zeit-Anzeige */
.time-diff {
  margin-left: 8px;
  font-size: 13px;
  font-weight: 600;
}

/* Farbcode der Zeit */
.green-time  { color: #16a34a; }
.yellow-time { color: #ca8a04; }
.red-time    { color: #dc2626; }

em {
  margin-left: 6px;
  font-size: 11px;
  opacity: 0.55;
}

/* ==========================================================
   MOVEMENT LOG
   ========================================================== */
.movements-section {
  margin-top: 46px;
}

.movement-list {
  padding-left: 20px;
}

.arrow {
  margin: 0 8px;
}

.movement-time {
  color: #6b7280;
}

/* ==========================================================
   FEHLERBOX
   ========================================================== */
.error-box {
  background: #fee2e2;
  color: #991b1b;
  padding: 12px;
  border-left: 4px solid #dc2626;
  border-radius: 6px;
  margin-bottom: 18px;
}

.muted {
  color: #777;
}

</style>
