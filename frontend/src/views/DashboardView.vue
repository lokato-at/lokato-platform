<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { useDashboardDataStore } from '@/stores/dashboardDataStore'
import type { Child, Movement, OccupancySnapshot, Room } from '@/stores/dashboardDataStore'

const store = useDashboardDataStore()
const childSearch = ref('')
const selectedRoomId = ref<number | null>(null)
const checkoutInProgress = reactive(new Set<number>())

const normalizedChildSearch = computed(() => childSearch.value.trim().toLowerCase())

function isEntityActive(entity: unknown): boolean {
  if (!entity || typeof entity !== 'object') return true
  const record = entity as Record<string, unknown>

  if (typeof record.is_active === 'boolean') return record.is_active
  if (typeof record.isActive === 'boolean') return record.isActive
  return true
}

interface RoomCard {
  room: Room
  snapshot: OccupancySnapshot
  visibleChildren: Child[]
  occupancyCount: number
  capacityLabel: string
  occupancyRatio: number
  status: 'ok' | 'warn' | 'over'
}

const roomCards = computed<RoomCard[]>(() => {
  const rooms = (store.rooms ?? []).filter((room) => isEntityActive(room))
  const roomQuery = ''
  const childQuery = normalizedChildSearch.value

  return rooms
    .map((room) => {
      const snapshot = store.occupancy[room.id] ?? {
        room,
        current_count: room.current_count ?? room.children?.length ?? 0,
        children: room.children ?? [],
      }

      const visibleChildren = (snapshot.children ?? []).filter((child) => {
        if (!isEntityActive(child)) return false
        if (!childQuery) return true

        const name = child.name?.toLowerCase() ?? ''
        const tracker = child.tracker_uid?.toLowerCase() ?? ''
        return name.includes(childQuery) || tracker.includes(childQuery)
      })

      const occupancyCount = snapshot.current_count ?? visibleChildren.length
      const capacity = room.capacity ?? 0
      const occupancyRatio = capacity > 0 ? Math.min((occupancyCount / capacity) * 100, 100) : 0
      const status: RoomCard['status'] = room.status?.over_capacity
        ? 'over'
        : room.status?.within_tolerance
          ? 'warn'
          : 'ok'

      return {
        room,
        snapshot,
        visibleChildren,
        occupancyCount,
        capacityLabel: room.capacity != null ? String(room.capacity) : '∞',
        occupancyRatio,
        status,
      }
    })
    .filter((card) => {
      const roomName = card.room.name?.toLowerCase() ?? ''
      const area = (card.room as Record<string, unknown>).area
      const areaText = typeof area === 'string' ? area.toLowerCase() : ''
      const matchesRoom = !roomQuery || roomName.includes(roomQuery) || areaText.includes(roomQuery)
      const matchesChild = !childQuery || card.visibleChildren.length > 0
      return matchesRoom && matchesChild
    })
    .sort((a, b) => a.room.name.localeCompare(b.room.name, 'de'))
})

const selectedRoomCard = computed<RoomCard | null>(() => {
  if (selectedRoomId.value == null) return null
  return roomCards.value.find((card) => card.room.id === selectedRoomId.value) ?? null
})

const selectedRoomChildren = computed<Child[]>(() => {
  const children = selectedRoomCard.value?.snapshot.children ?? []
  return children.filter((child) => isEntityActive(child))
})

const metrics = computed(() => {
  const cards = roomCards.value
  const overCapacity = cards.filter((card) => card.status === 'over').length
  const warningRooms = cards.filter(
    (card) => card.status === 'warn' || card.status === 'over',
  ).length

  return {
    activeRooms: cards.length,
    presentChildren: cards.reduce((sum, card) => sum + card.occupancyCount, 0),
    warningRooms,
    overCapacity,
  }
})

const filteredMovements = computed<Movement[]>(() => {
  const roomQuery = ''
  const childQuery = normalizedChildSearch.value

  return store.latestMovements.filter((movement) => {
    const child = movement.child
    if (child && !isEntityActive(child)) return false

    const childName = child?.name?.toLowerCase() ?? ''
    const childIdText = String(movement.child_id ?? '')
    const toRoom = movement.to_room?.name?.toLowerCase() ?? ''
    const fromRoom = movement.from_room?.name?.toLowerCase() ?? ''

    const roomMatch = !roomQuery || toRoom.includes(roomQuery) || fromRoom.includes(roomQuery)
    const childMatch =
      !childQuery || childName.includes(childQuery) || childIdText.includes(childQuery)

    return roomMatch && childMatch
  })
})

function formatDateTime(ts?: string) {
  if (!ts) return '--'
  return new Date(ts).toLocaleString('de-DE', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function openRoomDetails(roomId: number) {
  selectedRoomId.value = roomId
}

function closeRoomDetails() {
  selectedRoomId.value = null
}

async function checkoutChildFromRoom(child: Child, roomId: number) {
  if (checkoutInProgress.has(child.id)) return
  checkoutInProgress.add(child.id)

  try {
    await store.checkoutChild(child.id, roomId)
  } finally {
    checkoutInProgress.delete(child.id)
  }
}

onMounted(async () => {
  await store.fetchAllDashboardData()
  store.connectSSE()
})

onUnmounted(() => {
  store.disconnectSSE()
})
</script>

<template>
  <div class="dashboard">
    <header class="dashboard-header">
      <div>
        <h2>Dashboard</h2>
        <p class="muted">Snapshot + Live-Events in einer kompakten Übersicht.</p>
      </div>
      <span class="connection" :class="{ online: store.sseConnected }">
        {{ store.sseConnected ? 'SSE verbunden' : 'SSE verbindet…' }}
      </span>
    </header>

    <div class="left-column">
      <div class="toolbar">
        <input
          v-model="childSearch"
          type="search"
          class="input"
          placeholder="Kinder suchen…"
          aria-label="Kinder suchen"
        />
      </div>

      <p v-if="store.loading" class="info">Dashboard wird geladen…</p>
      <p v-else-if="store.error" class="error">{{ store.error }}</p>

      <section>
        <div v-if="!roomCards.length" class="empty-state">Keine passenden Kinder gefunden.</div>

        <div v-else class="rooms-grid">
          <article
            v-for="card in roomCards"
            :key="card.room.id"
            class="room"
            :class="card.status"
            role="button"
            tabindex="0"
            :aria-label="`Raum ${card.room.name} anzeigen`"
            @click="openRoomDetails(card.room.id)"
            @keydown.enter.prevent="openRoomDetails(card.room.id)"
            @keydown.space.prevent="openRoomDetails(card.room.id)"
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
                <span v-else class="avatar placeholder">{{
                  child.name.charAt(0).toUpperCase()
                }}</span>
                <span>{{ child.name }}</span>
              </li>
            </ul>
            <p v-else class="muted">Keine passenden Kinder.</p>
          </article>
        </div>
      </section>
    </div>

    <div class="right-column">
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

      <section class="movements">
        <h3>Letzte Bewegungen</h3>

        <ul v-if="filteredMovements.length" class="movement-list">
          <li
            v-for="movement in filteredMovements"
            :key="movement.id ?? `${movement.child_id}-${movement.occurred_at}`"
          >
            <div class="movement-main">
              <strong>{{ movement.child?.name ?? `Kind #${movement.child_id ?? '?'}` }}</strong>
              <span class="arrow"
                >{{ movement.from_room?.name ?? '?' }} → {{ movement.to_room?.name ?? '?' }}</span
              >
            </div>
            <time>{{ formatDateTime(movement.occurred_at) }}</time>
          </li>
        </ul>
        <p v-else class="muted">Keine Bewegungen für den aktuellen Filter.</p>
      </section>
    </div>

    <div v-if="selectedRoomCard" class="room-modal-backdrop" @click="closeRoomDetails">
      <div class="room-modal" role="dialog" aria-modal="true" @click.stop>
        <header class="room-modal-header">
          <div>
            <h3>{{ selectedRoomCard.room.name }}</h3>
            <p class="muted">
              {{ selectedRoomCard.occupancyCount }} / {{ selectedRoomCard.capacityLabel }} Kinder
            </p>
          </div>
          <button
            class="icon-button"
            type="button"
            @click="closeRoomDetails"
            aria-label="Schließen"
          >
            ×
          </button>
        </header>

        <ul v-if="selectedRoomChildren.length" class="child-list modal-child-list">
          <li v-for="child in selectedRoomChildren" :key="child.id" class="child-item">
            <img
              v-if="child.photo_url"
              :src="child.photo_url"
              :alt="`Foto von ${child.name}`"
              class="avatar"
            />
            <span v-else class="avatar placeholder">{{ child.name.charAt(0).toUpperCase() }}</span>
            <span class="child-name">{{ child.name }}</span>
            <button
              class="remove-child"
              type="button"
              :disabled="checkoutInProgress.has(child.id)"
              :aria-label="`${child.name} austragen`"
              @click.stop="checkoutChildFromRoom(child, selectedRoomCard.room.id)"
            >
              {{ checkoutInProgress.has(child.id) ? '…' : '✕' }}
            </button>
          </li>
        </ul>
        <p v-else class="muted">Keine Kinder im Raum.</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* ===== MAIN LAYOUT ===== */
.dashboard {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 35px;
  padding: 0 16px 24px;
}

.dashboard-header {
  grid-column: 1 / -1;
  grid-row: 1;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 21px;
  gap: 16px;
  text-align: left;
}

.left-column {
  grid-column: 1;
  display: grid;
  gap: 29px;
  align-content: start;
}

.right-column {
  grid-column: 2;
  display: grid;
  gap: 15px;
  align-content: start;
  width: 523px;
}

/* ===== SHARED STYLES ===== */
.muted {
  margin: 0;
  color: #64748b;
  font-size: 0.9rem;
}

/* ===== CONNECTION STATUS ===== */
.connection {
  border-radius: 999px;
  padding: 8px 12px;
  background: #fff4cc;
  color: #7a5c00;
  font-weight: 600;
  font-size: 15px;
}

.connection.online {
  background: #dcfce7;
  color: #166534;
}

/* ===== METRICS SECTION ===== */
.metrics {
  display: grid;
  width: 523px;
  gap: 20px;
  grid-template-columns: repeat(2, 1fr);
  grid-auto-rows: max-content;
}

.metric-card {
  width: 100%;
  min-height: 145px;
  border: 1px solid #e6edf3;
  border-radius: 5px;
  padding: 11px;
  background: #fff;
  box-shadow: 0 4px 13px 0 rgba(0, 0, 0, 0.25);
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.metric-label {
  margin: 0;
  color: #64748b;
  font-size: 21px;
  margin-bottom: 20px;
}

.metric-value {
  margin: 4px 0 0;
  font-size: 25px;
  font-weight: 700;
}

/* ===== TOOLBAR / SEARCH ===== */
.toolbar {
  display: grid;
  gap: 8px;
  grid-template-columns: 1fr;
}

.input {
  width: 628px;
  height: 58px;
  border: 1px solid #dbe2ea;
  border-radius: 5px;
  padding: 9px 11px;
  background: #fff;
  box-sizing: border-box;
  font-size: 1rem;
  box-shadow: 0 4px 13px 0 rgba(0, 0, 0, 0.25);
}

/* ===== ROOMS SECTION ===== */
.rooms-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 15px;
  align-items: start;
  grid-auto-rows: min-content;
}

.room {
  width: 100%;
  max-width: 302px;
  min-height: 148px;
  border: 1px solid #dbe4ff;
  border-radius: 5px;
  padding: 12px;
  background: #fff;
  text-align: left;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  box-shadow: 0 4px 13px 0 rgba(0, 0, 0, 0.25);
  cursor: pointer;
  align-self: start;
}

.room.warn {
  border-color: #f59e0b;
}

.room.over {
  border-color: #ef4444;
  background: #fff7f7;
}

.room-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}

.room-header h3 {
  margin-top: 10px;
  font-size: 21px;
}

.capacity {
  font-size: 0.86rem;
  color: #475569;
  background: #f8fafc;
  padding: 4px 8px;
  border-radius: 999px;
}

/* ===== CAPACITY BAR ===== */
.capacity-track {
  height: 21px;
  border-radius: 999px;
  background: #e2e8f0;
  overflow: hidden;
  margin-bottom: 10px;
}

.capacity-bar {
  height: 100%;
  background: linear-gradient(90deg, #60a5fa, #2563eb);
}

/* ===== CHILD LIST ===== */
.child-list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 6px;
}

.child-item {
  display: flex;
  align-items: center;
  gap: 8px;
}

.child-name {
  flex: 1;
}

.remove-child {
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #1f2937;
  border-radius: 999px;
  width: 28px;
  height: 28px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

.remove-child:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.avatar {
  width: 24px;
  height: 24px;
  border-radius: 999px;
  object-fit: cover;
}

.avatar.placeholder {
  background: #e2e8f0;
  color: #334155;
  font-size: 0.75rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

/* ===== MOVEMENTS SECTION ===== */
.movements {
  border: 1px solid #e6edf3;
  border-radius: 5px;
  padding: 12px;
  background: #fff;
  text-align: left;
  box-shadow: 0 4px 13px 0 rgba(0, 0, 0, 0.25);
}

.movements h3 {
  margin: 0 0 8px;
}

.movement-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 8px;
}

.movement-list li {
  border: 1px solid #edf2f7;
  border-radius: 9px;
  padding: 8px;
  display: grid;
  gap: 4px;
}

.movement-main {
  display: grid;
  gap: 4px;
}

.arrow {
  color: #475569;
  font-size: 0.86rem;
}

time {
  color: #64748b;
  font-size: 0.82rem;
}

/* ===== STATUS MESSAGES ===== */
.info,
.error {
  text-align: left;
  margin: 0;
}

.error {
  color: #b91c1c;
}

.empty-state {
  border: 1px dashed #dbe2ea;
  border-radius: 12px;
  padding: 16px;
  color: #64748b;
  text-align: center;
}

/* ===== MODAL ===== */
.icon-button {
  border: none;
  background: transparent;
  font-size: 24px;
  cursor: pointer;
  color: #475569;
}

.room-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.5);
  display: grid;
  place-items: center;
  padding: 16px;
  z-index: 1000;
}

.room-modal {
  width: 100%;
  max-width: 420px;
  background: #fff;
  border-radius: 12px;
  padding: 16px;
  box-shadow: 0px 4px 13px 0px rgba(0, 0, 0, 0.25);
  border-radius: 5px;
  text-align: left;
}

.room-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}

.modal-child-list {
  max-height: 320px;
  overflow: auto;
  padding-right: 4px;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1200px) {
  .dashboard {
    grid-template-columns: 1fr;
  }

  .left-column,
  .right-column {
    grid-column: 1;
  }

  .right-column {
    width: 100%;
  }

  .metrics {
    width: 100%;
  }

  .input {
    width: 100%;
  }
}

@media (max-width: 700px) {
  .dashboard {
    gap: 20px;
    padding: 0 12px 20px;
  }

  .dashboard-header {
    flex-direction: column;
    align-items: flex-start;
    font-size: 18px;
  }

  .toolbar {
    grid-template-columns: 1fr;
  }

  .rooms-grid {
    grid-template-columns: 1fr;
  }

  .metrics {
    grid-template-columns: repeat(2, 1fr);
    width: 100%;
    gap: 15px;
  }

  .metric-card {
    min-height: 110px;
    padding: 8px;
  }

  .metric-label {
    font-size: 16px;
    margin-bottom: 8px;
  }

  .metric-value {
    font-size: 20px;
  }

  .room {
    max-width: none;
  }

  .input {
    height: 48px;
    width: 100%;
  }

  .room-header h3 {
    font-size: 1.05rem;
  }

  .capacity-track {
    height: 14px;
  }

  .room-modal {
    max-width: 100%;
  }
}
</style>
