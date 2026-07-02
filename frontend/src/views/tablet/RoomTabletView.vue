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

function statusBarUpdate(num: number) {
  const statusBar = document.querySelector(".statusbar");
  if (!statusBar) return;
  const capacity = room.value?.capacity ?? 0;
  const fill = statusBar.querySelector(".statusfill") as HTMLElement | null;
  if (!fill) return;
  const percentage = capacity > 0 ? Math.min((currentCount.value / capacity) * 100, 100) : 0;
  fill.style.width = `${percentage}%`;
  if (percentage < 45) {
    fill.style.background = "linear-gradient(90deg, rgba(0, 125, 83, 0.60) 0.01%, rgba(102, 102, 102, 0.00) 99.98%), #63B852";
    fill.style.backgroundBlendMode = "hard-light, normal";
    fill.style.boxShadow = "0 4px 4px 0 rgba(0, 125, 83, 0.30)";
  } else if (percentage < 75) {
    fill.style.background = "linear-gradient(90deg, rgba(216, 72, 47, 0.60) 0.01%, rgba(102, 102, 102, 0.00) 99.98%), #F3EE4C";
    fill.style.backgroundBlendMode = "hard-light, normal";
    fill.style.boxShadow = "0 4px 4px 0 rgba(216, 72, 47, 0.30)";
  } else {
    fill.style.background = "linear-gradient(90deg, rgba(178, 0, 56, 0.60) 0.01%, rgba(102, 102, 102, 0.00) 99.98%), #D8482F";
    fill.style.backgroundBlendMode = "hard-light, normal";
    fill.style.boxShadow = "0 4px 4px 0 rgba(216, 72, 47, 0.30)";
  }


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
    <svg class="shape" xmlns="http://www.w3.org/2000/svg" width="733" height="751" viewBox="0 0 733 751" fill="none">
      <g filter="url(#filter0_d_54919_121)">
        <path d="M528 0C566.66 6.4426e-08 598 31.3401 598 70V116.455C598
               132.471 610.984 145.455 627 145.455H659C697.66 145.455 729 176.795 729
               215.455V673C729 711.66 697.66 743 659 743H447.5C408.84 743 377.5 711.66 377.5
               673V444.448C377.5 405.788 346.16 374.448 307.5 374.448H74C35.3401 374.448 4 343.108
               4 304.448V70C4.00002 31.3401 35.3401 6.44261e-08 74 0H528Z" fill="#F5F5F5" />
      </g>
      <defs>
        <filter id="filter0_d_54919_121" x="0" y="0" width="733" height="751" filterUnits="userSpaceOnUse"
          color-interpolation-filters="sRGB">
          <feFlood flood-opacity="0" result="BackgroundImageFix" />
          <feColorMatrix in="SourceAlpha" type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
            result="hardAlpha" />
          <feOffset dy="4" />
          <feGaussianBlur stdDeviation="2" />
          <feComposite in2="hardAlpha" operator="out" />
          <feColorMatrix type="matrix" values="0 0 0 0 0.219608 0 0 0 0 0.25098 0 0 0 0 0.788235 0 0 0 0.5 0" />
          <feBlend mode="normal" in2="BackgroundImageFix" result="effect1_dropShadow_54919_121" />
          <feBlend mode="normal" in="SourceGraphic" in2="effect1_dropShadow_54919_121" result="shape" />
        </filter>
      </defs>
    </svg>
    <header class="tablet-header">
      <div class="room-info">
        <div class="room-icon-placeholder"></div>
        <h2 class="room-title">{{ room?.name ?? "Room" }}</h2>
        <p class="meta">Aktuelle Belegung</p>
      </div>
      <button class="search"><svg xmlns="http://www.w3.org/2000/svg" width="25" height="25" viewBox="0 0 33 33"
          fill="none">
          <path d="M29.7417 32.25L18.4542 20.9625C17.5583 21.6792 16.5281 22.2465 15.3635
  22.6646C14.199 23.0826 12.9597 23.2917 11.6458 23.2917C8.39097 23.2917 5.63658 22.1641 3.38267
  19.909C1.12875 17.6539 0.00119539 14.8995 9.47972e-07 11.6458C-0.0011935 8.39217 1.12636 5.63778
  3.38267 3.38267C5.63897 1.12756 8.39336 0 11.6458 0C14.8983 0 17.6533 1.12756 19.9108 3.38267C22.1683
  5.63778 23.2953 8.39217 23.2917 11.6458C23.2917 12.9597 23.0826 14.199 22.6646 15.3635C22.2465 16.5281 21.6792
  17.5583 20.9625 18.4542L32.25 29.7417L29.7417 32.25ZM11.6458 19.7083C13.8854 19.7083 15.7894 18.9248 17.3577 17.3577C18.926
  15.7906 19.7095 13.8866 19.7083 11.6458C19.7071 9.40506 18.9236 7.50171 17.3577 5.93579C15.7918 4.36988 13.8878 3.58572 11.6458
  3.58333C9.40386 3.58094 7.50051 4.3651 5.93579 5.93579C4.37107 7.50649 3.58692 9.40983 3.58333 11.6458C3.57975 13.8818 4.3639 15.7858
  5.93579 17.3577C7.50768 18.9296 9.41103 19.7131 11.6458 19.7083Z" fill="#3840C9" fill-opacity="0.8" />
        </svg></button>
      <div class="status">
        <div class="statusbar">
          <div class="statusfill">{{ statusBarUpdate(currentCount) }}</div>
        </div>
        <div class="count-line" :class="occupancyStatus">
          <span class="count">{{ currentCount }}</span>
          <span class="capacity">/ {{ capacityLabel }}</span>
        </div>
        <span v-if="occupancyStatusLabel" class="status-pill" :class="occupancyStatus">
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
      <div class="content-back">
        <p v-if="!children.length" class="empty">Keine Kinder im Raum.</p>

        <ul v-else class="child-grid">
          <li v-for="child in children" :key="child.id" class="child-card">
            <ChildPhoto :child="child" />
            <span class="name">{{ child.name }}</span>
          </li>
        </ul>
      </div>
    </section>

    <!-- Begrüßungs-Animation für neu eintreffende Kinder -->
    <div v-if="currentAnimation" class="animation-overlay" @click="dismissAnimation">
      <video :key="currentAnimation" :src="videoSrc" :muted="videoMuted" autoplay playsinline class="animation-video"
        @ended="onVideoEnded" />
      <p v-if="!soundUnlocked && branding.animations.playWithSound" class="sound-hint">
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
  background: linear-gradient(180deg, #B4D2E8 0%, #D2B6E3 100%);
  color: #0f172a;
}

/*
.tablet-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 24px;
  text-align: center;
}*/

.shape {
  position: absolute;
  top: 30px;

  width: 496px;
  height: 508px;
  fill: #F5F5F5;
  filter: drop-shadow(0 4px 4px rgba(56, 64, 201, 0.50));
}

.room-info {
  position: absolute;
  width: 315px;
  height: 315px;
  aspect-ratio: 1/1;
  left: 120px;
  top: 175px;
  border-radius: 85px;
  background: #4EB0F1;
  box-shadow: 0 4px 4px 0 rgba(56, 64, 201, 0.40) inset, 7px 8px 9.4px 0 rgba(56, 64, 201, 0.40);
}

.room-icon-placeholder {
  position: relative;
  align-self: center;
  justify-self: center;
  width: 190px;
  height: 190px;
  top: 25px;
  border-radius: 120px;
  background: lightgray;
}

.room-title {
  position: relative;
  top: 55px;
  text-align: center;
  font-size: 2.1rem;
  margin: 0 0 10px;
  color: #ffffff;
}

.meta {
  position: relative;
  bottom: 15px;
  margin: 0;
  color: #64748b;
  font-size: 1.1rem;
}

.status {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 6px;
  text-align: right;

}

.statusbar {
  position: absolute;
  left: 75px;
  top: 60px;
  width: 314px;
  height: 55px;
  border-radius: 20px;
  background: #FFFEFE;
  box-shadow: 5px 4px 4px 0 rgba(56, 64, 201, 0.50), 0 4px 4px 0 rgba(56, 64, 201, 0.25) inset;
}

.statusfill {
  align-self: flex-start;
  position: relative;
  top: 8px;
  left: 8px;
  height: 42px;
  border-radius: 18px;
  max-width: 306px;

}

.count {
  font-size: 3rem;
  font-weight: 700;
  line-height: 1;
}

.capacity {
  position: relative;
  top: -6px;
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

.status-pill.warn {
  background: #fef3c7;
  color: #92400e;
}

.status-pill.over {
  background: #fee2e2;
  color: #991b1b;
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
  position: absolute;
  top: 90px;
  right: 15px;




}

.content-back {
  position: relative;

  width: 390px;
  height: 430px;
  border-radius: 20px;
  background: #ECECEC;
  box-shadow: 0 4px 4px 0 rgba(56, 64, 201, 0.40) inset;
  overflow-y: auto;
}

.search {
  position: absolute;
  top: 42px;
  left: 455px;
  width: 70px;
  height: 70px;
  border-radius: 24px;
  border: none;
  background: #F5F5F5;
  box-shadow: 0 4px 4px 0 rgba(56, 64, 201, 0.40) inset, 3px 4px 4px 0 rgba(56, 64, 201, 0.50);
}

.child-grid {
  list-style: none;
  margin: 0;
  padding: 16px;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px;
  position: relative;
  top: 0px;
  width: 340px;

  border-radius: 26px;




}

.child-card {
  display: grid;
  align-items: center;
  
  padding: 16px;
  border-radius: 20px;
  background: #ffffff;
  font-size: 1.1rem;


}


.child-card:nth-child(4n + 1) {
  border-top: 1px solid #E62200;
  border-right: 2px solid #E62200;
  border-bottom: 8px solid #E62200;
  border-left: 2px solid #E62200;
  background: #FAAE20;
}

.child-card:nth-child(4n + 2) {
  border-top: 1px solid #F27100;
  border-right: 2px solid #F27100;
  border-bottom: 8px solid #F27100;
  border-left: 2px solid #F27100;
  background: #F3EE4C;
}

.child-card:nth-child(4n + 3) {
  border-top: 1px solid #007D53;
  border-right: 2px solid #007D53;
  border-bottom: 8px solid #007D53;
  border-left: 2px solid #007D53;
  background: #63B852;
}

.child-card:nth-child(4n + 4) {
  border-top: 1px solid #551BB3;
  border-right: 2px solid #551BB3;
  border-bottom: 8px solid #551BB3;
  border-left: 2px solid #551BB3;
  background: #4EB0F1;
}


.child-photo {
  width: 115px;
  height: 115px;
  border-radius: 18px;
  object-fit: cover;
  align-self: center;
  justify-self: center;
  position: relative;
  top: 0px;


}

.child-photo.placeholder {
  width: 115px !important;
  height: 115px !important;
  display: flex;
  align-self: center;
  justify-self: center;
  background: #e2e8f0;
  color: #334155;
  font-weight: 700;

}

.name {
  width: 120px;
  font-size: 15px;
  font-weight: 600;
  padding-top: 12px;
  position: relative;
  display: grid;
  align-items: center;
  text-align: center;
  justify-self: center;

  font-family: Nunito, sans-serif;


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
  from {
    opacity: 0;
  }

  to {
    opacity: 1;
  }
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
