<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from "vue";
import { useRoute } from "vue-router";
import { useRoomTabletStore } from "@/stores/roomTabletStore";

const store = useRoomTabletStore();
const route = useRoute();

const roomId = computed(() => {
  const param = Array.isArray(route.params.roomId)
    ? route.params.roomId[0]
    : route.params.roomId;
  const value = Number(param);
  return Number.isFinite(value) && value > 0 ? value : null;
});

const room = computed(() => store.snapshot.room);
const children = computed(() => store.snapshot.children ?? []);
const currentCount = computed(() => store.snapshot.current_count ?? children.value.length);

const connectionLabel = computed(() =>
  store.sseConnected ? "Live verbunden" : "Live Verbindung...",
);

const capacityLabel = computed(() => {
  const capacity = room.value?.capacity;
  return typeof capacity === "number" ? String(capacity) : "-";
});

function childInitials(name?: string) {
  if (!name) return "?";
  return name.trim().charAt(0).toUpperCase();
}

async function loadRoom(nextRoomId: number | null) {
  if (!nextRoomId) {
    store.disconnectSSE();
    return;
  }

  await store.loadRoomSnapshot(nextRoomId);
  store.connectSSE(nextRoomId);
}

onMounted(() => {
  void loadRoom(roomId.value);
});

watch(roomId, (next, prev) => {
  if (next === prev) return;
  store.disconnectSSE();
  void loadRoom(next);
});

onUnmounted(() => {
  store.disconnectSSE();
});
</script>

<template>
  <section class="tablet">
    <header class="tablet-header">
      <div>
        <h2 class="room-title">{{ room?.name ?? "Room" }}</h2>
        <p class="meta">Aktuelle Belegung</p>
      </div>
      <div class="status">
        <span class="count">{{ currentCount }}</span>
        <span class="capacity">/ {{ capacityLabel }}</span>
        <span class="connection" :class="{ online: store.sseConnected }">
          {{ connectionLabel }}
        </span>
      </div>
    </header>

    <p v-if="!roomId" class="error">Ungueltige Raum-ID.</p>
    <p v-else-if="store.loading" class="info">Lade Raumdaten...</p>
    <p v-else-if="store.error" class="error">{{ store.error }}</p>

    <section v-else class="content">
      <p v-if="!children.length" class="empty">Keine Kinder im Raum.</p>

      <ul v-else class="child-grid">
        <li v-for="child in children" :key="child.id" class="child-card">
          <img
            v-if="child.photo_url"
            :src="child.photo_url"
            :alt="`Photo of ${child.name}`"
            class="avatar"
          />
          <span v-else class="avatar placeholder">{{ childInitials(child.name) }}</span>
          <span class="name">{{ child.name }}</span>
        </li>
      </ul>
    </section>
  </section>
</template>

<style scoped>
.tablet {
  display: grid;
  gap: 24px;
  padding: 24px 32px 40px;
  min-height: 100vh;
  background: #f8fafc;
  color: #0f172a;
}

.tablet-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 24px;
  text-align: left;
}

.room-title {
  font-size: 2.5rem;
  margin: 0 0 4px;
}

.meta {
  margin: 0;
  color: #64748b;
  font-size: 1.1rem;
}

.status {
  display: grid;
  justify-items: end;
  gap: 6px;
  text-align: right;
}

.count {
  font-size: 3rem;
  font-weight: 700;
  line-height: 1;
}

.capacity {
  font-size: 1.2rem;
  color: #64748b;
}

.connection {
  padding: 6px 12px;
  border-radius: 999px;
  background: #fde68a;
  color: #92400e;
  font-weight: 600;
}

.connection.online {
  background: #bbf7d0;
  color: #166534;
}

.content {
  display: grid;
  gap: 16px;
}

.child-grid {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.child-card {
  display: grid;
  grid-template-columns: auto 1fr;
  align-items: center;
  gap: 12px;
  padding: 16px;
  border-radius: 16px;
  background: #ffffff;
  box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
  font-size: 1.1rem;
}

.avatar {
  width: 48px;
  height: 48px;
  border-radius: 999px;
  object-fit: cover;
}

.avatar.placeholder {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #e2e8f0;
  color: #334155;
  font-weight: 700;
}

.name {
  font-size: 1.2rem;
  font-weight: 600;
}

.info,
.error,
.empty {
  font-size: 1.2rem;
  margin: 0;
}

.error {
  color: #b91c1c;
}

.empty {
  color: #475569;
}

@media (max-width: 900px) {
  .tablet {
    padding: 20px;
  }

  .tablet-header {
    flex-direction: column;
    align-items: center;
    text-align: center;
  }

  .status {
    justify-items: center;
    text-align: center;
  }
}
</style>

