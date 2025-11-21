<template>
  <div class="dashboard-view">
    <!-- --------------------------------------------------------
         SEITENTITEL + KURZBESCHREIBUNG
         -------------------------------------------------------- -->
    <h1>Dashboard</h1>

    <p class="desc">
      Öffentliche Live-Übersicht aller Räume und der Kinder in jedem Raum.
      Die Daten stammen aus der öffentlichen API (GET /rooms, GET /rooms/{id}/occupancy, GET /movement-log).
    </p>

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
          v-for="room in store.rooms"
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

          <!-- KINDELISTE -->
          <h4>Kinder</h4>

          <!-- Wenn Kinder drin sind -->
          <ul v-if="store.occupancy[room.id]?.children.length" class="child-list">
            <li
              v-for="child in store.occupancy[room.id].children"
              :key="child.id"
              class="child-entry"
            >
              <!-- optionales Foto -->
              <img
                v-if="child.photo_url"
                :src="child.photo_url"
                alt="Foto"
                class="child-photo"
              />

              <img
                v-else
                src="https://via.placeholder.com/40?text=?"
                alt="?"
                class="child-photo"
              />

              <!-- Name des Kindes -->
              <span>{{ child.name }}</span>
            </li>
          </ul>

          <!-- Wenn keine Kinder da sind -->
          <p v-else class="muted">Derzeit keine Kinder in diesem Raum.</p>
        </article>
      </div>
    </section>

    <!-- ========================================================= -->
    <!-- 2) LETZTE 5 BEWEGUNGEN AUS DEM MOVEMENT LOG              -->
    <!-- ========================================================= -->
    <section class="movements-section">
      <h2>Letzte Bewegungen</h2>

      <!-- Liste der letzten Bewegungen -->
      <ol class="movement-list">
        <li
          v-for="m in store.latestMovements"
          :key="m.id"
        >
          <!-- Kind -->
          <strong>{{ m.child?.name ?? "?" }}</strong>

          <!-- Pfeil → -->
          <span class="arrow">→</span>

          <!-- Zielraum -->
          <strong>{{ m.to_room?.name ?? "?" }}</strong>

          <!-- Datum / Zeit -->
          <span class="movement-time">
            ({{ formatDate(m.occurred_at) }})
          </span>
        </li>
      </ol>

      <!-- Wenn keine Bewegungen existieren -->
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
| Wir holen den Dashboard-Store und rufen beim Laden der Seite die
| API-Endpunkte ab. Der Store kümmert sich um das Laden der Räume,
| Occupancy und der letzten 5 Bewegungen.
*/
import { onMounted } from "vue";
import { useDashboardDataStore } from "@/stores/dashboardDataStore";

const store = useDashboardDataStore();

/*
|--------------------------------------------------------------------------
| LEBENSZYKLUS: Daten laden
|--------------------------------------------------------------------------
*/
onMounted(async () => {
  await store.fetchAllDashboardData();
});

/*
|--------------------------------------------------------------------------
| DATUM FORMATIEREN
|--------------------------------------------------------------------------
| Kleiner Helper, der ein ISO-Datum in eine deutsche lesbare Form bringt.
| Beispiel:
|   "2025-11-20T18:01:17+00:00"
| → "20.11.2025, 19:01"
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
/* ===== Layout ===== */
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
  margin-bottom: 30px;
}

/* ===== Räume ===== */
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

.room-capacity strong {
  font-weight: 600;
}

.room-status strong.green {
  color: #009900;
}

.room-status strong.yellow {
  color: #cc8800;
}

.room-status strong.red {
  color: #cc0000;
}

/* ===== Kinderliste ===== */
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

.muted {
  color: #777;
}

/* ===== Bewegungen ===== */
.movements-section {
  margin-top: 40px;
}

.movement-list {
  padding-left: 20px;
}

.arrow {
  margin: 0 6px;
}

.movement-time {
  color: #777;
  font-size: 0.9em;
}

/* ===== Fehleranzeige ===== */
.error-box {
  background: #fdd;
  padding: 10px;
  border-left: 4px solid red;
  margin-bottom: 15px;
}
</style>
