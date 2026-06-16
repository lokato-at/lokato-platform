<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from "vue";
import { useRoute } from "vue-router";
import { useTVRoomStore } from "@/stores/tvRoomStore";
import type { Room } from "@/stores/dashboardDataStore";


const store = useTVRoomStore();
const route = useRoute();


const rooms = computed(() => store.rooms ?? []);

/* const capacityLabel = computed(() => {
  const capacity = room.value?.capacity;
  return typeof capacity === "number" ? String(capacity) : "-";
}); */

const connectionLabel = computed(() =>
  store.sseConnected ? "Live verbunden" : "Live Verbindung...",
);

const roomCountLabel = computed(() =>
  rooms.value.length > 0 ? `${rooms.value.length} Räume` : "Keine Räume gefunden",
);

function roomFillStyle(room: Room) {
  const count = room.current_count ?? room.children?.length ?? 0;
  const capacity = room.capacity ?? 0;
  const percentage = capacity > 0 ? Math.min((count / capacity) * 100, 100) : 0;
  return { width: `${percentage}%` };
}

/* function statusBarUpdate(num: number) {
  const statusBar = document.querySelector(".statusbar");
  if (!statusBar) return;
  const capacity = room.value?.capacity ?? 0;
  const fill = statusBar.querySelector(".satusfill") as HTMLElement | null;
  if (!fill) return;
  const percentage = capacity > 0 ? Math.min((currentCount.value / capacity) * 100, 100) : 0;
  fill.style.width = `${percentage}%`;
  

} */

function childInitials(name?: string) {
  if (!name) return "?";
  return name.trim().charAt(0).toUpperCase();
}

/* async function loadRoom(nextRoomId: number | null) {
  if (!nextRoomId) {
    store.disconnectSSE();
    return;
  }

  await store.loadRoomOccupancy(nextRoomId);
  store.connectSSE(nextRoomId);
} */

onMounted(() => {
  void store.loadRooms();
  store.connectSSE();
});


onUnmounted(() => {
  store.disconnectSSE();
});




</script>

<template>
    <section class="tv-view">
    <header class="header">
      <div>
        <h1>Willkommen im Hort Pregarten!</h1>
        <p class="subtitle">{{ roomCountLabel }}</p>
      </div>
      <span class="connection" :class="{ online: store.sseConnected }">
        {{ connectionLabel }}
      </span>
    </header>

    <p v-if="store.loading" class="info">Lade Raumdaten...</p>
    <p v-else-if="store.error" class="error">{{ store.error }}</p>

    <section v-else class="room-grid">
      <article v-for="room in rooms" :key="room.id" class="room-card">
        <div class="room-header">
          <div>
            <div class="room-name">{{ room.name }}</div>
            <!-- <div class="room-area">{{ room.area ?? "Bereich nicht verfügbar" }}</div> -->
          </div>
          <div class="room-count">
            {{ room.current_count ?? room.children?.length ?? 0 }} / {{ room.capacity ?? "-" }} Kinder
          </div>
        </div>

        <div class="statusbar">
          <div class="satusfill" :style="roomFillStyle(room)"></div>
        </div>

        <div class="children">
          <strong>Aktuelle Kinder</strong>
          <p v-if="!(room.children?.length)">Keine Kinder im Raum</p>
          <ul v-else class="child-list">
            <li v-for="child in room.children" :key="child.id" class="child-pill">
              {{ childInitials(child.name) }}
            </li>
          </ul>
        </div>
      </article>
    </section>
  </section>
</template>

<style scoped lang="scss">
.tv-view {
  min-height: 100vh;
  padding: 24px;
  background: linear-gradient(180deg, #b4d2e8 0%, #d2b6e3 100%);
  color: #0f172a;
}

.header {
  display: flex;
  width: 858.5px;
    height: 120px;
  justify-content: center;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 24px;
  background: rgba(245, 245, 245, 0.90);
  border-radius: 16px;
  box-shadow: 0 4px 4px 0 rgba(178, 0, 56, 0.25) inset;
    filter: drop-shadow(0 2px 3px rgba(216, 72, 47, 0.40));
    
}

.heater h1 {
    color: #2A000D;
    font-family: Nunito;
font-size: 48px;
font-style: normal;
font-weight: 400;
line-height: normal;
}

.subtitle {
  margin: 4px 0 0;
  color: #475569;
  font-size: 1rem;
}

.connection {
  padding: 8px 16px;
  border-radius: 999px;
  background: #fde68a;
  color: #92400e;
  font-weight: 600;
}

.connection.online {
  background: #bbf7d0;
  color: #166534;
}

.info,
.error {
  margin: 0 0 18px;
  font-weight: 600;
}

.error {
  color: #b91c1c;
}

.room-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 20px;
  background: rgba(245, 245, 245, 0.90);
  border-radius: 16px;
}

.room-card {
  background: #ffffff;
  border-radius: 24px;
  padding: 24px;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
}

.room-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 18px;
}

.room-name {
  font-size: 1.4rem;
  font-weight: 700;
}

.room-area {
  margin-top: 6px;
  color: #64748b;
}

.room-count {
  font-size: 1rem;
  color: #0f172a;
  font-weight: 700;
  text-align: right;
}

.statusbar {
  width: 100%;
  height: 18px;
  border-radius: 999px;
  background: #e2e8f0;
  overflow: hidden;
  margin-bottom: 20px;
}

.satusfill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, rgba(56, 189, 248, 0.85), rgba(34, 197, 94, 0.85));
  transition: width 0.25s ease;
}

.children {
  display: grid;
  gap: 12px;
}

.child-list {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.child-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 40px;
  min-height: 40px;
  border-radius: 999px;
  background: #f8fafc;
  color: #0f172a;
  font-weight: 700;
}
</style>
