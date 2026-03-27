<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useDashboardDataStore } from "@/stores/dashboardDataStore";
import type { Child, Movement, OccupancySnapshot, Room } from "@/stores/dashboardDataStore";

const store = useDashboardDataStore();
const roomSearch = ref("");
const childSearch = ref("");

const normalizedRoomSearch = computed(() => roomSearch.value.trim().toLowerCase());
const normalizedChildSearch = computed(() => childSearch.value.trim().toLowerCase());

function isEntityActive(entity: unknown): boolean {
  if (!entity || typeof entity !== "object") return true;
  const record = entity as Record<string, unknown>;

  if (typeof record.is_active === "boolean") return record.is_active;
  if (typeof record.isActive === "boolean") return record.isActive;
  return true;
}

interface RoomCard {
  room: Room;
  snapshot: OccupancySnapshot;
  visibleChildren: Child[];
  occupancyCount: number;
  capacityLabel: string;
  status: "ok" | "warn" | "over";
}

const roomCards = computed<RoomCard[]>(() => {
  const rooms = (store.rooms ?? []).filter((room) => isEntityActive(room));
  const roomQuery = normalizedRoomSearch.value;
  const childQuery = normalizedChildSearch.value;

  return rooms
    .map((room) => {
      const snapshot = store.occupancy[room.id] ?? {
        room,
        current_count: room.current_count ?? room.children?.length ?? 0,
        children: room.children ?? [],
      };

      const visibleChildren = (snapshot.children ?? []).filter((child) => {
        if (!isEntityActive(child)) return false;
        if (!childQuery) return true;

        const name = child.name?.toLowerCase() ?? "";
        const tracker = child.tracker_uid?.toLowerCase() ?? "";
        return name.includes(childQuery) || tracker.includes(childQuery);
      });

      const occupancyCount = snapshot.current_count ?? visibleChildren.length;
      const status: RoomCard["status"] =
        room.status?.over_capacity ? "over" : room.status?.within_tolerance ? "warn" : "ok";

      return {
        room,
        snapshot,
        visibleChildren,
        occupancyCount,
        capacityLabel: room.capacity != null ? String(room.capacity) : "∞",
        status,
      };
    })
    .filter((card) => {
      const roomName = card.room.name?.toLowerCase() ?? "";
      const area = (card.room as Record<string, unknown>).area;
      const areaText = typeof area === "string" ? area.toLowerCase() : "";
      const matchesRoom = !roomQuery || roomName.includes(roomQuery) || areaText.includes(roomQuery);
      const matchesChild = !childQuery || card.visibleChildren.length > 0;
      return matchesRoom && matchesChild;
    })
    .sort((a, b) => a.room.name.localeCompare(b.room.name, "de"));
});

const metrics = computed(() => {
  const cards = roomCards.value;
  return {
    activeRooms: cards.length,
    presentChildren: cards.reduce((sum, card) => sum + card.occupancyCount, 0),
    warningRooms: cards.filter((card) => card.status === "warn" || card.status === "over").length,
  };
});

const filteredMovements = computed<Movement[]>(() => {
  const roomQuery = normalizedRoomSearch.value;
  const childQuery = normalizedChildSearch.value;

  return store.latestMovements.filter((movement) => {
    const child = movement.child;
    if (child && !isEntityActive(child)) return false;

    const childName = child?.name?.toLowerCase() ?? "";
    const childIdText = String(movement.child_id ?? "");
    const toRoom = movement.to_room?.name?.toLowerCase() ?? "";
    const fromRoom = movement.from_room?.name?.toLowerCase() ?? "";

    const roomMatch = !roomQuery || toRoom.includes(roomQuery) || fromRoom.includes(roomQuery);
    const childMatch = !childQuery || childName.includes(childQuery) || childIdText.includes(childQuery);

    return roomMatch && childMatch;
  });
});

function formatTime(ts?: string) {
  if (!ts) return "--:--";
  return new Date(ts).toLocaleTimeString("de-DE", { hour: "2-digit", minute: "2-digit" });
}

onMounted(async () => {
  await store.fetchAllDashboardData();
  store.connectSSE();
});

onUnmounted(() => {
  store.disconnectSSE();
});
</script>

<template>
  <div class="dashboard">
    <header class="dashboard-header">
      <div>
        <h2>Live Dashboard</h2>
        <p class="muted">Snapshot + Live-Events in einer kompakten Übersicht.</p>
      </div>
      <span class="connection" :class="{ online: store.sseConnected }">
        {{ store.sseConnected ? "SSE verbunden" : "SSE verbindet…" }}
      </span>
    </header>

    <div class="metrics">
      <article class="metric-card">
        <p class="metric-label">Aktive Räume</p>
        <p class="metric-value">{{ metrics.activeRooms }}</p>
      </article>
      <article class="metric-card">
        <p class="metric-label">Anwesende Kinder</p>
        <p class="metric-value">{{ metrics.presentChildren }}</p>
      </article>
      <article class="metric-card">
        <p class="metric-label">Warnungen</p>
        <p class="metric-value">{{ metrics.warningRooms }}</p>
      </article>
    </div>

    <div class="toolbar">
      <input
        v-model="roomSearch"
        type="search"
        class="input"
        placeholder="Räume suchen…"
        aria-label="Räume suchen"
      />
      <input
        v-model="childSearch"
        type="search"
        class="input"
        placeholder="Kinder in Räumen suchen…"
        aria-label="Kinder in Räumen suchen"
      />
    </div>

    <p v-if="store.loading" class="info">Dashboard wird geladen…</p>
    <p v-else-if="store.error" class="error">{{ store.error }}</p>

    <div class="dashboard-grid">
      <section>
        <div v-if="!roomCards.length" class="empty-state">Keine passenden Räume gefunden.</div>

        <div v-else class="rooms-grid">
          <article
            v-for="card in roomCards"
            :key="card.room.id"
            class="room"
            :class="card.status"
          >
            <header class="room-header">
              <h3>{{ card.room.name }}</h3>
              <span class="capacity">{{ card.occupancyCount }} / {{ card.capacityLabel }}</span>
            </header>

            <ul v-if="card.visibleChildren.length" class="child-list">
              <li v-for="child in card.visibleChildren" :key="child.id">
                {{ child.name }}
              </li>
            </ul>
            <p v-else class="muted">Keine passenden Kinder.</p>
          </article>
        </div>
      </section>

      <section class="movements">
        <h3>Letzte Bewegungen</h3>

        <ul v-if="filteredMovements.length" class="movement-list">
          <li v-for="movement in filteredMovements" :key="movement.id ?? `${movement.child_id}-${movement.occurred_at}`">
            <span>{{ movement.child?.name ?? `Kind #${movement.child_id ?? "?"}` }}</span>
            <span class="arrow">→</span>
            <span>{{ movement.to_room?.name ?? "Unbekannter Raum" }}</span>
            <time>{{ formatTime(movement.occurred_at) }}</time>
          </li>
        </ul>
        <p v-else class="muted">Keine Bewegungen für den aktuellen Filter.</p>
      </section>
    </div>
  </div>
</template>

<style scoped>
.dashboard { display: grid; gap: 16px; padding: 0 16px 24px; }
.dashboard-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; text-align: left; }
.muted { margin: 0; color: #64748b; font-size: 0.9rem; }
.connection { border-radius: 999px; padding: 8px 12px; background: #fff4cc; color: #7a5c00; font-weight: 600; }
.connection.online { background: #dcfce7; color: #166534; }
.metrics { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
.metric-card { border: 1px solid #e6edf3; border-radius: 12px; padding: 11px; background: #fff; }
.metric-label { margin: 0; color: #64748b; font-size: 0.83rem; }
.metric-value { margin: 4px 0 0; font-size: 1.2rem; font-weight: 700; }
.toolbar { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(180px, 1fr)); }
.input { border: 1px solid #dbe2ea; border-radius: 10px; padding: 9px 11px; background: #fff; }
.dashboard-grid { display: grid; gap: 16px; grid-template-columns: 2fr 1fr; align-items: start; }
.rooms-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.room { border: 1px solid #dbe4ff; border-radius: 12px; padding: 12px; background: #fff; text-align: left; }
.room.warn { border-color: #f59e0b; }
.room.over { border-color: #ef4444; background: #fff7f7; }
.room-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 8px; }
.room-header h3 { margin: 0; font-size: 1rem; }
.capacity { font-size: 0.86rem; color: #475569; background: #f8fafc; padding: 4px 8px; border-radius: 999px; }
.child-list { margin: 0; padding-left: 18px; display: grid; gap: 4px; }
.movements { border: 1px solid #e6edf3; border-radius: 12px; padding: 12px; background: #fff; text-align: left; }
.movements h3 { margin: 0 0 8px; }
.movement-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
.movement-list li { display: grid; grid-template-columns: 1fr auto 1fr auto; gap: 8px; align-items: center; font-size: 0.92rem; }
.arrow { color: #64748b; }
time { color: #64748b; font-size: 0.83rem; }
.info, .error { text-align: left; margin: 0; }
.error { color: #b91c1c; }
.empty-state { border: 1px dashed #dbe2ea; border-radius: 12px; padding: 16px; color: #64748b; text-align: center; }
@media (max-width: 980px) {
  .dashboard-grid { grid-template-columns: 1fr; }
}
@media (max-width: 700px) {
  .toolbar { grid-template-columns: 1fr; }
}
</style>
