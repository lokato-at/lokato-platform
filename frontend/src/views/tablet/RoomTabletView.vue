<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute } from "vue-router";
import { useRoomTabletStore } from "@/stores/roomTabletStore";
import { useBranding } from "@/composables/useBranding";
import ChildPhoto from "@/components/ChildPhoto.vue";

const store = useRoomTabletStore();
const route = useRoute();
const { config: branding } = useBranding();

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
  store.sseConnected ? "Live" : "verbindet...",
);

const capacityLabel = computed(() => {
  const capacity = room.value?.capacity;
  return typeof capacity === "number" ? String(capacity) : "-";
});

const occupancyStatus = computed<'ok' | 'warn' | 'over'>(() => {
  const status = room.value?.status;
  if (status?.over_capacity) return 'over';
  if (status?.within_tolerance) return 'warn';
  return 'ok';
});

const occupancyStatusLabel = computed(() => {
  if (occupancyStatus.value === 'over') return 'Überbelegt';
  if (occupancyStatus.value === 'warn') return 'Warnung';
  return null;
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

// Welcome-Animation wenn eine neue Kind-ID in der children-Liste auftaucht.
// Cooldown verhindert Mehrfach-Trigger bei Bursts (mehrere Kinder gleichzeitig).
const knownChildIds = ref<Set<number>>(new Set());
const isFirstSnapshot = ref(true);
const currentAnimation = ref<string | null>(null);
const lastTriggerAt = ref(0);
const soundUnlocked = ref(false);

// Browser-Autoplay-Policy: Ton braucht erste User-Geste.
function unlockSound() {
  soundUnlocked.value = true;
}

onMounted(() => {
  window.addEventListener("click", unlockSound, { once: true });
  window.addEventListener("touchstart", unlockSound, { once: true, passive: true });
  void loadRoom(roomId.value);
});

onUnmounted(() => {
  window.removeEventListener("click", unlockSound);
  window.removeEventListener("touchstart", unlockSound);
  store.disconnectSSE();
});

watch(roomId, (next, prev) => {
  if (next === prev) return;
  store.disconnectSSE();
  // Reset bei Raumwechsel, sonst werden bestehende Kinder im neuen Raum als
  // "Ankunft" gewertet.
  knownChildIds.value = new Set();
  isFirstSnapshot.value = true;
  currentAnimation.value = null;
  void loadRoom(next);
});

watch(
  () => children.value.map((c) => c.id),
  (nextIds) => {
    const nextSet = new Set(nextIds);

    if (isFirstSnapshot.value) {
      // Initial-Snapshot: nur Baseline setzen, keine Animation.
      knownChildIds.value = nextSet;
      isFirstSnapshot.value = false;
      return;
    }

    const arrivals = nextIds.filter((id) => !knownChildIds.value.has(id));
    knownChildIds.value = nextSet;

    if (arrivals.length > 0) {
      tryTriggerAnimation();
    }
  },
);

function tryTriggerAnimation() {
  if (room.value?.is_active === false) return;

  const files = branding.value.animations.files;
  if (files.length === 0) return;
  if (currentAnimation.value) return;

  const cooldownMs = (branding.value.animations.cooldownSeconds || 10) * 1000;
  if (Date.now() - lastTriggerAt.value < cooldownMs) return;

  const pick = files[Math.floor(Math.random() * files.length)];
  if (pick === undefined) return;
  currentAnimation.value = pick;
  lastTriggerAt.value = Date.now();
}

// Raum-Deaktivierung waehrend laufender Animation: sofort abbrechen, sonst
// spielt sie ueber dem "geschlossen"-Banner.
watch(
  () => room.value?.is_active,
  (isActive) => {
    if (isActive === false && currentAnimation.value) {
      currentAnimation.value = null;
    }
  },
);

const videoSrc = computed(() =>
  currentAnimation.value
    ? `/branding/animations/${encodeURIComponent(currentAnimation.value)}`
    : "",
);

const videoMuted = computed(
  () => !branding.value.animations.playWithSound || !soundUnlocked.value,
);

function onVideoEnded() {
  currentAnimation.value = null;
}

function dismissAnimation() {
  currentAnimation.value = null;
}
</script>

<template>
  <section class="tablet">
    <header class="tablet-header">
      <div>
        <h2 class="room-title">{{ room?.name ?? "Room" }}</h2>
        <p class="meta">Aktuelle Belegung</p>
      </div>
      <div class="status">
        <div class="count-line" :class="occupancyStatus">
          <span class="count">{{ currentCount }}</span>
          <span class="capacity">/ {{ capacityLabel }}</span>
        </div>
        <span
          v-if="occupancyStatusLabel"
          class="status-pill"
          :class="occupancyStatus"
        >
          {{ occupancyStatusLabel }}
        </span>
        <span class="connection" :class="{ online: store.sseConnected }">
          {{ connectionLabel }}
        </span>
      </div>
    </header>

    <p v-if="!roomId" class="error">Ungueltige Raum-ID.</p>
    <p v-else-if="store.loading" class="info">Lade Raumdaten...</p>
    <p v-else-if="store.error" class="error">{{ store.error }}</p>

    <div v-else-if="room?.is_active === false" class="room-closed">
      <p class="room-closed-title">Raum derzeit geschlossen</p>
      <p class="room-closed-sub">Der Raum „{{ room?.name }}" ist aktuell nicht aktiv.</p>
    </div>

    <section v-else class="content">
      <p v-if="!children.length" class="empty">Keine Kinder im Raum.</p>

      <ul v-else class="child-grid">
        <li v-for="child in children" :key="child.id" class="child-card">
          <ChildPhoto :child="child" />
          <span class="name">{{ child.name }}</span>
        </li>
      </ul>
    </section>

    <!-- Begrüßungs-Animation für neu eintreffende Kinder -->
    <div
      v-if="currentAnimation"
      class="animation-overlay"
      @click="dismissAnimation"
    >
      <video
        :key="currentAnimation"
        :src="videoSrc"
        :muted="videoMuted"
        autoplay
        playsinline
        class="animation-video"
        @ended="onVideoEnded"
      />
      <p
        v-if="!soundUnlocked && branding.animations.playWithSound"
        class="sound-hint"
      >
        Tippen für Ton
      </p>
    </div>
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

.count-line {
  display: flex;
  align-items: baseline;
  gap: 6px;
  transition: color 0.2s;
}
.count-line.warn  { color: #d97706; }
.count-line.over  { color: #dc2626; }

.count {
  font-size: 3rem;
  font-weight: 700;
  line-height: 1;
}

.capacity {
  font-size: 1.2rem;
  color: #64748b;
}

.count-line.warn .capacity,
.count-line.over .capacity {
  color: inherit;
  opacity: 0.75;
}

.status-pill {
  padding: 4px 12px;
  border-radius: 999px;
  font-weight: 700;
  font-size: 0.85rem;
  letter-spacing: 0.4px;
  text-transform: uppercase;
}
.status-pill.warn { background: #fef3c7; color: #92400e; }
.status-pill.over { background: #fee2e2; color: #991b1b; }

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

.room-closed {
  display: grid;
  gap: 12px;
  padding: 48px 24px;
  border-radius: 20px;
  background: #fef3c7;
  color: #78350f;
  text-align: center;
  border: 2px dashed #d97706;
}

.room-closed-title {
  margin: 0;
  font-size: 2rem;
  font-weight: 700;
}

.room-closed-sub {
  margin: 0;
  font-size: 1.2rem;
  color: #92400e;
}

/* ----- Animation-Overlay ----- */
.animation-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.88);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 999;
  cursor: pointer;
  animation: fadeIn 0.2s ease-out;
}

.animation-video {
  max-width: 90vw;
  max-height: 90vh;
  border-radius: 20px;
  box-shadow: 0 16px 48px rgba(0, 0, 0, 0.6);
  background: black;
}

.sound-hint {
  position: absolute;
  bottom: 32px;
  background: rgba(255, 255, 255, 0.95);
  color: #0f172a;
  padding: 10px 22px;
  border-radius: 999px;
  margin: 0;
  font-weight: 600;
  font-size: 1rem;
  pointer-events: none;
  letter-spacing: 0.2px;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to   { opacity: 1; }
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
