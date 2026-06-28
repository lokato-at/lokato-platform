<script setup lang="ts">
import ChildBadge from '@/components/ChildBadge.vue'
import type { Child, OccupancySnapshot, Room } from '@/stores/dashboardDataStore'

defineProps<{
  room: Room
  snapshot: OccupancySnapshot
  visibleChildren: Child[]
  occupancyCount: number
  capacityLabel: string
  occupancyRatio: number
  status: 'ok' | 'warn' | 'over'
}>()

const emit = defineEmits<{
  (e: 'open', roomId: number): void
}>()

function open(roomId: number): void {
  emit('open', roomId)
}
</script>

<template>
  <article
    class="room"
    :class="[status, { inactive: room.is_active === false }]"
    role="button"
    tabindex="0"
    :aria-label="`Raum ${room.name} anzeigen`"
    @click="open(room.id)"
    @keydown.enter.prevent="open(room.id)"
    @keydown.space.prevent="open(room.id)"
  >
    <header class="room-header">
      <h3>
        {{ room.name }}
        <span v-if="room.is_active === false" class="inactive-pill">inaktiv</span>
      </h3>
      <span class="capacity">{{ occupancyCount }} / {{ capacityLabel }}</span>
    </header>

    <div v-if="room.capacity" class="capacity-track">
      <div class="capacity-bar" :style="{ width: `${occupancyRatio}%` }" />
    </div>

    <ul v-if="visibleChildren.length" class="child-list">
      <li v-for="child in visibleChildren" :key="child.id" class="child-item">
        <ChildBadge :child="child" size="sm" />
      </li>
    </ul>
    <p v-else class="muted">Keine passenden Kinder.</p>
  </article>
</template>

<style scoped>
.room {
  background: #ffffff;
  border-radius: 16px;
  padding: 16px;
  box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
  cursor: pointer;
  transition: transform 0.15s, box-shadow 0.15s;
  display: grid;
  gap: 12px;
}

.room:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(15, 23, 42, 0.12); }

.room.warn { border-left: 4px solid #f59e0b; }
.room.over { border-left: 4px solid #dc2626; }

.room.inactive {
  opacity: 0.55;
  background: #f1f5f9;
  border-left: 4px solid #94a3b8;
}

.room.inactive:hover {
  opacity: 0.75;
}

.inactive-pill {
  display: inline-block;
  margin-left: 6px;
  background: #e2e8f0;
  color: #475569;
  font-size: 0.55em;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 999px;
  vertical-align: middle;
  letter-spacing: 0.5px;
  text-transform: uppercase;
}

.room-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.room-header h3 {
  margin: 0;
  font-size: 1.1rem;
}

.capacity {
  font-weight: 600;
  color: #475569;
}

.capacity-track {
  width: 100%;
  height: 6px;
  background: #e2e8f0;
  border-radius: 999px;
  overflow: hidden;
}

.capacity-bar {
  height: 100%;
  background: linear-gradient(90deg, #38bdf8, #2563eb);
  transition: width 0.3s;
}

.child-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-height: 220px;
  overflow-y: auto;
}

.child-item {
  padding: 4px 0;
}

.muted {
  color: #64748b;
  font-size: 0.9rem;
  margin: 0;
}
</style>
