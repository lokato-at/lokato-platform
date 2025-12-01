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

          <ul
            v-if="filteredOccupancy[room.id]?.children.length"
            class="child-list"
          >
            <li
              v-for="child in filteredOccupancy[room.id].children"
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

              <!-- --------------------------------------------------------
                   KINDERNAME + LIVE-AUFENTHALTSZEIT (jede Sekunde)
                   --------------------------------------------------------

                   timeInRoomByChild[...] liefert den formatierten Zeitwert
                   getDurationColor(...) liefert die Farbe abhängig von der Dauer
              -->
              <span>
                {{ child.name }} <em style="font-size: small;  opacity:0.6;">im Raum seit</em>
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
   AUTO-REFRESH (30 SEKUNDEN)
   ========================================================== */
let refreshInterval: number | null = null;
const AUTO_REFRESH_MS = 30000;

onMounted(async () => {
  await store.fetchAllDashboardData();

  refreshInterval = window.setInterval(async () => {
    try {
      await store.fetchAllDashboardData();
    } catch (e) {
      console.warn("Auto-refresh failed", e);
    }
  }, AUTO_REFRESH_MS);
});

onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval);
});

/* ==========================================================
   SUCHFELDER
   ========================================================== */
const search = ref("");
const searchRoom = ref("");

/* ==========================================================
   🔥 LIVE-ZEITUPDATER (JEDEN 1 SEKUNDE)
   ----------------------------------------------------------
   "now" aktualisiert sich jede Sekunde.

   Dadurch wird *nur* der computed timeInRoomByChild
   jede Sekunde neu berechnet → die Aufenthaltszeit läuft live
   ohne die API neu aufzurufen.
   ========================================================== */
const now = ref(Date.now());

setInterval(() => {
  now.value = Date.now();
}, 1000);

/* ==========================================================
   🔥 ZEIT PRO KIND (HH:MM:SS) – computed abhängig von "now"
   ========================================================== */
const timeInRoomByChild = computed(() => {
  const result: Record<number, string> = {};
  const movements = store.latestMovements;

  if (!movements || !Array.isArray(movements)) return result;

  const current = now.value; // ⬅ live aktualisiert

  // Für jedes Kind nur die letzte Bewegung speichern
  const byChild = new Map<number, any>();

  movements.forEach((m: any) => {
    const childId = Number(m.child?.id); // movement liefert "id"
    if (!childId || !m.occurred_at) return;

    if (!byChild.has(childId)) {
      byChild.set(childId, m);
    }
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
   🔥 FARBKLASSEN FÜR ZEIT NACH DAUER
   ----------------------------------------------------------
   < 10 Minuten  → grün
   < 30 Minuten  → gelb
   ≥ 30 Minuten  → rot
   ========================================================== */
function getDurationColor(childId: number) {
  const txt = timeInRoomByChild.value?.[childId];
  if (!txt) return "";

  const [h, m, s] = txt.split(":").map(Number);
  const totalMin = h * 60 + m;

  if (totalMin < 10) return "green-time";
  if (totalMin < 30) return "yellow-time";
  return "red-time";
}

/* ==========================================================
   FILTER KINDER
   ========================================================== */
const filteredOccupancy = computed(() => {
  const occ = store.occupancy || {};
  if (!search.value) return occ;

  const q = search.value.toLowerCase();
  const result: Record<number, any> = {};

  for (const [roomId, data] of Object.entries(occ)) {
    const children = (data as any).children || [];

    const filtered = children.filter((c: any) =>
      (c.name ?? "").toLowerCase().includes(q)
    );

    result[Number(roomId)] = {
      ...(data as any),
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
  return rooms.filter((r: any) => (r.name ?? "").toLowerCase().includes(q));
});

/* ==========================================================
   DATUMFORMAT
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
   LAYOUT UND STYLE (unverändert)
   ========================================================== */

.dashboard-view {
  padding: 30px;
  max-width: 1100px;
  margin: auto;
  font-family: system-ui, sans-serif;
}

h1 { margin-bottom: 10px; }
.desc { color: #666; margin-bottom: 20px; }

.search-bar {
  display: flex;
  justify-content: center;
  margin: 20px 0 30px 0;
  position: relative;
}

.search-bar input {
  width: 100%;
  max-width: 500px;
  padding: 10px 14px;
  border-radius: 12px;
  border: 1px solid #ccc;
  font-size: 15px;
  padding-right: 40px;
}

.rooms-grid {
  display: grid;
  gap: 22px;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
}

.room-card {
  padding: 18px;
  background: white;
  border-radius: 10px;
  border: 1px solid #ddd;
  box-shadow: 0 3px 7px rgba(0,0,0,0.05);
}

/* ==========================================================
   🔥 ZEIT-FARBKLASSEN
   ========================================================== */
.time-diff {
  margin-left: 8px;
  font-size: 12px;
  font-weight: bold;
}

.green-time {
  color: #009900;
}

.yellow-time {
  color: #cc8800;
}

.red-time {
  color: #cc0000;
}

/* ========================================================== */

.child-entry {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 0;
}

.child-photo {
  width: 40px;
  height: 40px;
  border-radius: 6px;
  object-fit: cover;
  border: 1px solid #ccc;
}

.muted { color: #777; }

.movements-section { margin-top: 40px; }
.movement-time { color: #777; }

.error-box {
  background: #fdd;
  padding: 10px;
  border-left: 4px solid red;
  margin-bottom: 15px;
}
</style>
