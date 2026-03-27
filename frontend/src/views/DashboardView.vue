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
  occupancyRatio: number;
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
      const capacity = room.capacity ?? 0;
      const occupancyRatio = capacity > 0 ? Math.min((occupancyCount / capacity) * 100, 100) : 0;
      const status: RoomCard["status"] =
        room.status?.over_capacity ? "over" : room.status?.within_tolerance ? "warn" : "ok";

      return {
        room,
        snapshot,
        visibleChildren,
        occupancyCount,
        capacityLabel: room.capacity != null ? String(room.capacity) : "∞",
        occupancyRatio,
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
  const overCapacity = cards.filter((card) => card.status === "over").length;
  const warningRooms = cards.filter((card) => card.status === "warn" || card.status === "over").length;

  return {
    activeRooms: cards.length,
    presentChildren: cards.reduce((sum, card) => sum + card.occupancyCount, 0),
    warningRooms,
    overCapacity,
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

function formatDateTime(ts?: string) {
  if (!ts) return "--";
  return new Date(ts).toLocaleString("de-DE", {
    day: "2-digit",
    month: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
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
      <article class="metric-card">
        <p class="metric-label">Überbelegt</p>
        <p class="metric-value">{{ metrics.overCapacity }}</p>
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

            <div class="capacity-track" v-if="card.room.capacity">
              <div class="capacity-bar" :style="{ width: `${card.occupancyRatio}%` }" />
            </div>

            <ul v-if="card.visibleChildren.length" class="child-list">
              <li v-for="child in card.visibleChildren" :key="child.id" class="child-item">
                <img
                  v-if="child.photo_url"
                  :src="child.photo_url"
                  :alt="`Foto von ${child.name}`"
                  class="avatar"
                />
                <span v-else class="avatar placeholder">{{ child.name.charAt(0).toUpperCase() }}</span>
                <span>{{ child.name }}</span>
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
            <div class="movement-main">
              <strong>{{ movement.child?.name ?? `Kind #${movement.child_id ?? "?"}` }}</strong>
              <span class="arrow">{{ movement.from_room?.name ?? "?" }} → {{ movement.to_room?.name ?? "?" }}</span>
            </div>
            <time>{{ formatDateTime(movement.occurred_at) }}</time>
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
.metrics { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); }
.metric-card { border: 1px solid #e6edf3; border-radius: 12px; padding: 11px; background: #fff; }
.metric-label { margin: 0; color: #64748b; font-size: 0.83rem; }
.metric-value { margin: 4px 0 0; font-size: 1.2rem; font-weight: 700; }
.toolbar { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(180px, 1fr)); }
.input { border: 1px solid #dbe2ea; border-radius: 10px; padding: 9px 11px; background: #fff; }
.dashboard-grid { display: grid; gap: 16px; grid-template-columns: 2fr 1fr; align-items: start; }
.rooms-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px; }
.room { border: 1px solid #dbe4ff; border-radius: 12px; padding: 12px; background: #fff; text-align: left; }
.room.warn { border-color: #f59e0b; }
.room.over { border-color: #ef4444; background: #fff7f7; }
.room-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 8px; }
.room-header h3 { margin: 0; font-size: 1rem; }
.capacity { font-size: 0.86rem; color: #475569; background: #f8fafc; padding: 4px 8px; border-radius: 999px; }
.capacity-track { height: 8px; border-radius: 999px; background: #e2e8f0; overflow: hidden; margin-bottom: 10px; }
.capacity-bar { height: 100%; background: linear-gradient(90deg, #60a5fa, #2563eb); }
.child-list { margin: 0; padding: 0; list-style: none; display: grid; gap: 6px; }
.child-item { display: flex; align-items: center; gap: 8px; }
.avatar { width: 24px; height: 24px; border-radius: 999px; object-fit: cover; }
.avatar.placeholder { background: #e2e8f0; color: #334155; font-size: 0.75rem; display: inline-flex; align-items: center; justify-content: center; }
.movements { border: 1px solid #e6edf3; border-radius: 12px; padding: 12px; background: #fff; text-align: left; }
.movements h3 { margin: 0 0 8px; }
.movement-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
.movement-list li { border: 1px solid #edf2f7; border-radius: 9px; padding: 8px; display: grid; gap: 4px; }
.movement-main { display: grid; gap: 4px; }
.arrow { color: #475569; font-size: 0.86rem; }
time { color: #64748b; font-size: 0.82rem; }
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
