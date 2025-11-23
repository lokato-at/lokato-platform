<template>
  <div class="dashboard-view">
    <!-- --------------------------------------------------------
         TITELBEREICH
         -------------------------------------------------------- -->
    <h1>Admin Dashboard</h1>

    <p class="desc">
      Öffentliche Live-Übersicht aller Räume und der Kinder in jedem Raum.
      Die Daten stammen aus der öffentlichen API (GET /rooms, GET /rooms/{id}/occupancy, GET /movement-log).
    </p>

    <!-- --------------------------------------------------------
         SUCHFELD (NEU!)
         -------------------------------------------------------- -->
    <div class="search-bar">
      <input
        v-model="search"
        type="text"
        placeholder="🔍 Kind suchen…"
      />
      <button v-if="search" class="clear-btn" @click="search = ''">✖</button>
    </div>

    <!-- RAUM-SUCHE -->
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

      <!-- Grid Layout für Raumkarten -->
      <div class="rooms-grid">
        <!-- Jede Karte stellt einen Raum dar -->
        <article
          class="room-card"
          v-for="room in filteredRooms"
          :key="room.id"
        >
          <!-- Raumname -->
          <h3>{{ room.name }}</h3>

          <!-- aktuelle Anzahl Kinder im Raum -->
          <p class="room-capacity">
            Kapazität:
            <strong>{{ room.current_count }} / {{ room.capacity }}</strong>
          </p>

          <!-- Raumstatus: Überfüllt / Toleranzbereich / OK -->
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

          <!-- ================================
               KINDELISTE (mit Suchfilter)
               ================================ -->
          <h4>Kinder</h4>

          <!-- Wenn gefilterte Kinder existieren -->
          <ul
            v-if="filteredOccupancy[room.id]?.children.length"
            class="child-list"
          >
            <li
              v-for="child in filteredOccupancy[room.id].children"
              :key="child.id"
              class="child-entry"
            >
              <!-- Foto oder Platzhalter -->
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

              <!-- Name -->
              <span>{{ child.name }}</span>
            </li>
          </ul>

          <!-- Kein Treffer für Raum -->
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
        <li
          v-for="m in store.latestMovements"
          :key="m.id"
        >
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
/*
|--------------------------------------------------------------------------
| IMPORTS
|--------------------------------------------------------------------------
*/
import { ref, computed, onMounted, onUnmounted } from "vue";
import { useDashboardDataStore } from "@/stores/dashboardDataStore";

import { useAdminDataStore } from "@/stores/adminDataStore.ts";

const store = useDashboardDataStore();

/*
|--------------------------------------------------------------------------
| AUTO REFRESH SETUP
|--------------------------------------------------------------------------
| - Beim Mounten wird einmal initial geladen.
| - Danach läuft ein Interval, das periodisch die Daten neu holt.
| - Beim Unmount wird das Interval wieder entfernt.
| - Intervalldauer ist aktuell 30s (30000 ms). Du kannst das leicht anpassen.
*/
let refreshInterval: number | null = null;
const AUTO_REFRESH_MS = 30000; // 30 Sekunden

onMounted(async () => {
  // initial load
  await store.fetchAllDashboardData();

  // auto-refresh starten
  refreshInterval = window.setInterval(async () => {
    // Best-effort Aktualisierung: Fehler werden intern im Store behandelt.
    try {
      // optional: du kannst hier auch nur einzelne Endpoints refreshen,
      // z.B. store.fetchLatestMovements(), um Last zu sparen.
      await store.fetchAllDashboardData();
      // Debug/Log — kannst du auskommentieren
      // console.log("⟳ Dashboard auto-refreshed");
    } catch (e) {
      // nichts weiter tun — store zeigt Fehler an
      console.warn("Auto-refresh failed", e);
    }
  }, AUTO_REFRESH_MS);
});

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval);
    refreshInterval = null;
  }
});

/*
|--------------------------------------------------------------------------
| SUCHFELD (reactiver Textinput)
|--------------------------------------------------------------------------
*/
const search = ref("");
const searchRoom = ref("");


/*
|--------------------------------------------------------------------------
| GEFILTERTE OCCUPANCY (Kinder werden nach Suche gefiltert)
|--------------------------------------------------------------------------
| - Räume bleiben sichtbar
| - Kinderliste pro Raum wird nach dem Suchbegriff gefiltert
| - Gesucht wird in:
|     * Name
|     * tracker_uid (falls vorhanden)
*/
const filteredOccupancy = computed(() => {
  // Guard: falls occupancy noch nicht geladen ist, gib leeres Objekt zurück
  const occ = store.occupancy || {};

  if (!search.value) return occ;

  const q = search.value.toLowerCase();
  const result: Record<number, any> = {};

  for (const [roomId, data] of Object.entries(occ)) {
    const children = data.children || [];

    const filtered = children.filter((c: any) =>
      (c.name ?? "").toLowerCase().includes(q) ||
      ((c.tracker_uid ?? "").toLowerCase().includes(q))
    );

    result[Number(roomId)] = {
      ...data,
      children: filtered
    };
  }

  return result;
});

/*
|--------------------------------------------------------------------------
| GEFILTERTE RÄUME
|--------------------------------------------------------------------------
| Räume werden ausgeblendet, wenn der Name NICHT zum Suchbegriff passt.
| Wenn store.rooms noch null ist, geben wir ein leeres Array zurück.
*/
const filteredRooms = computed(() => {
  const rooms = store.rooms || [];

  if (!searchRoom.value) return rooms;

  const q = searchRoom.value.toLowerCase();
  return rooms.filter((r: any) =>
    (r.name ?? "").toLowerCase().includes(q)
  );
});

/*
|--------------------------------------------------------------------------
| DATUM FORMATIEREN → Deutsch
|--------------------------------------------------------------------------
*/
const formatDate = (iso?: string) => {
  if (!iso) return "";
  const d = new Date(iso);
  return d.toLocaleString("de-DE", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
};
</script>

<style scoped>
/* ===== Gesamt-Layout ===== */
.dashboard-view {
  padding: 30px;
  max-width: 1100px;
  margin: auto;
  font-family: system-ui, sans-serif;
}

h1 {
  margin-bottom: 10px;
}

.desc {
  color: #666;
  margin-bottom: 20px;
}

/* =========================================================
   SUCHFELD
   ========================================================= */
.search-bar {
  display: flex;
  justify-content: center;
  margin: 20px 0 30px 0;
  position: relative;
  width: 100%;
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

.search-bar input:focus {
  outline: none;
  border-color: #2d7bff;
  box-shadow: 0 0 4px rgba(45, 123, 255, 0.4);
}

/* Achtung: clear-btn ist absolut positioniert; bei zwei Suchleisten ist es OK,
   könnte aber bei sehr kleinen Screens leicht überlappen */
.clear-btn {
  position: absolute;
  right: calc(50% - 230px);
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  cursor: pointer;
  font-size: 16px;
  color: #777;
}

.clear-btn:hover {
  color: black;
}

/* =========================================================
   Räume
   ========================================================= */
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
  transition: transform 0.1s ease;
}

.room-card:hover {
  transform: translateY(-3px);
}

.room-status strong.green { color: #009900; }
.room-status strong.yellow { color: #cc8800; }
.room-status strong.red   { color: #cc0000; }

/* =========================================================
   Kinderliste
   ========================================================= */
.child-list {
  list-style: none;
  padding: 0;
  margin-top: 10px;
}

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

/* =========================================================
   Bewegungen
   ========================================================= */
.movements-section { margin-top: 40px; }
.movement-list { padding-left: 20px; }
.arrow { margin: 0 6px; }
.movement-time { color: #777; }

/* =========================================================
   Fehler
   ========================================================= */
.error-box {
  background: #fdd;
  padding: 10px;
  border-left: 4px solid red;
  margin-bottom: 15px;
}
</style>
