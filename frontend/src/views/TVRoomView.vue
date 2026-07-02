<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useTVRoomStore } from "@/stores/tvRoomStore";
import type { Room } from "@/stores/dashboardDataStore";

const store = useTVRoomStore();
const currentArea = ref<"EG_Außenbereich" | "UG">("EG_Außenbereich");
const cycleIntervalMs = 8000;
let cycleTimer: number | undefined;

const rooms = computed(() => store.rooms ?? []);

const filteredRooms = computed(() => {
  if (currentArea.value === "EG_Außenbereich") {
    return rooms.value.filter((room) => {
      const area = normalizeArea(room.area);
      return area === "EG" || area === "AUSSENBEREICH";
    });
  }
  return rooms.value.filter((room) => normalizeArea(room.area) === currentArea.value);
});

const roomCountLabel = computed(() => {
  const visibleRooms = filteredRooms.value;
  return visibleRooms.length > 0 ? `${visibleRooms.length} Räume` : "Keine Räume gefunden";
});

function normalizeArea(area?: string | null) {
  return area?.trim().toUpperCase() ?? "";
}

function cycleArea() {
  currentArea.value = currentArea.value === "EG_Außenbereich" ? "UG" : "EG_Außenbereich";
}

function roomFillStyle(room: Room) {
  const count = room.current_count ?? room.children?.length ?? 0;
  const capacity = room.capacity ?? 0;
  const percentage = capacity > 0 ? Math.min((count / capacity) * 100, 100) : 0;
  return { width: `${percentage}%` };
}


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
  cycleTimer = window.setInterval(cycleArea, cycleIntervalMs);
});

onUnmounted(() => {
  if (cycleTimer != null) {
    window.clearInterval(cycleTimer);
  }
  store.disconnectSSE();
});




</script>

<template>
    <section class="tv-view">

      <svg width="903" height="186" viewBox="0 0 903 186" fill="none" xmlns="http://www.w3.org/2000/svg">
<g filter="url(#filter0_di_55369_4)">
<path d="M0 36C0 16.6701 15.67 1 35 1H865C884.33 1 900 16.67 900 36V71.8534C900 91.1323 884.41 106.781 865.131 106.853L615.496 107.787C606.662 107.787 599.5 114.949 599.5 123.783V146C599.5 165.33 583.83 181 564.5 181H335.236C315.814 181 300.106 165.186 300.237 145.764L300.392 122.718C300.452 113.902 293.321 106.723 284.504 106.723H35C15.6701 106.723 0 91.0527 0 71.7228V36Z" fill="#F5F5F5"/>
</g>
<defs>
<filter id="filter0_di_55369_4" x="-3" y="0" width="906" height="186" filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
<feFlood flood-opacity="0" result="BackgroundImageFix"/>
<feColorMatrix in="SourceAlpha" type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0" result="hardAlpha"/>
<feOffset dy="2"/>
<feGaussianBlur stdDeviation="1.5"/>
<feComposite in2="hardAlpha" operator="out"/>
<feColorMatrix type="matrix" values="0 0 0 0 0.847059 0 0 0 0 0.282353 0 0 0 0 0.184314 0 0 0 0.4 0"/>
<feBlend mode="normal" in2="BackgroundImageFix" result="effect1_dropShadow_55369_4"/>
<feBlend mode="normal" in="SourceGraphic" in2="effect1_dropShadow_55369_4" result="shape"/>
<feColorMatrix in="SourceAlpha" type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0" result="hardAlpha"/>
<feOffset dy="4"/>
<feGaussianBlur stdDeviation="2"/>
<feComposite in2="hardAlpha" operator="arithmetic" k2="-1" k3="1"/>
<feColorMatrix type="matrix" values="0 0 0 0 0.698039 0 0 0 0 0 0 0 0 0 0.219608 0 0 0 0.25 0"/>
<feBlend mode="normal" in2="shape" result="effect2_innerShadow_55369_4"/>
</filter>
</defs>
</svg>



    <header class="header">
      <div class="subheader">
        <h1>Willkommen im Hort Pregarten!</h1>
        <p class="subtitle">{{ roomCountLabel }}</p>
        <section class="area-labels">
          <div v-if="currentArea === 'EG_Außenbereich'" class="room-area-eg" :class="{ active: currentArea === 'EG_Außenbereich' }">Erdgeschoss</div>
          <div v-if="currentArea === 'UG'" class="room-area-og" :class="{ active: currentArea === 'UG' }">Obergeschoss</div>
        </section>
      </div>
      <!-- <span class="connection" :class="{ online: store.sseConnected }">
        {{ connectionLabel }}
      </span> -->
    </header>

    <p v-if="store.loading" class="info">Lade Raumdaten...</p>
    <p v-else-if="store.error" class="error">{{ store.error }}</p>

    <div v-else class="room-grid">
      <article v-for="room in filteredRooms" :key="room.id" class="room-card">
        <div class="room-header">
          <div class="room-icon-placeholder"></div>
            <div class="room-name">{{ room.name }}</div>
            <!-- <div class="room-area">{{ room.area ?? "Bereich nicht verfügbar" }}</div> -->

          <div class="room-count">
            {{ room.current_count ?? room.children?.length ?? 0 }} / {{ room.capacity ?? "-" }}
          </div>
        </div>

        <div class="statusbar">
          <div class="satusfill" :style="roomFillStyle(room)" ></div>
        </div>

        <!-- <div class="children">
          <strong>Aktuelle Kinder</strong>
          <p v-if="!(room.children?.length)">Keine Kinder im Raum</p>
          <ul v-else class="child-list">
            <li v-for="child in room.children" :key="child.id" class="child-pill">
              {{ childInitials(child.name) }}
            </li>
          </ul>
        </div> -->
      </article>
    </div>
  </section>
</template>

<style scoped>
.tv-view {
  min-height: 100vh;
  padding: 20px;
  background: linear-gradient(180deg, #b4d2e8 0%, #d2b6e3 100%);
  color: #0f172a;
}
.tv-svg {
     display: block;
    margin: auto;
    width: 850px;
  height: 200px;
}

.header {
  display: flex;
  position: absolute;
  width: 100%;
    height: 230px;
    top: -10px;
   right: 0px;
  justify-content: center;
  align-items: center;
  flex-wrap: wrap;
  gap: 3px;
  margin-bottom: 22px;

}

/* .subheader {

  position: absolute;
  display: inline-block;
  justify-content: center;
  align-items: center;

} */

.header h1 {
    color: #2A000D;
font-family: Nunito;
font-size: 38px;
font-style: normal;
font-weight: 500;
line-height: normal;
margin-bottom: 4px;
}



.area-labels {

  display: flex;
  justify-content: center;
  align-items: center;

}

.room-area-eg,
.room-area-og {
  width: 250px;
  height: 55px;
  border-radius: 18px;
  align-items: center;
  justify-content: center;
  display: flex;
  font-size: 24px;
  font-weight: 700;
  transition: all 180ms ease-in-out;
}

.room-area-eg {
  background: #F27100;
  color: white;
}

.room-area-og {
  background: #D8482F;
  color: white;
}

.room-area-eg.active {
  transform: scale(1.02);
  box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.45);
}

.room-area-og.active {
  transform: scale(1.02);
  box-shadow: 0 0 0 3px rgba(216, 72, 47, 0.22);
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
  background: rgba(245, 245, 245, 0.80);
  padding: 20px;
  border-radius: 16px;
}

.room-grid svg {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  padding: 20px;
  
}

.room-card {
  max-width: 320px;
  height: 175px;
  background: #ffffff;
  border-radius: 24px;
  padding: 20px;
  
}

.room-card:nth-child(1) {
  border-radius: 25px;
background: linear-gradient(90deg, rgba(99, 184, 82, 0.30) -50%, rgba(102, 102, 102, 0.00) 100%);
box-shadow: 0 4px 4px 0 rgba(0, 125, 83, 0.40);
}

.room-card:nth-child(2) {
border-radius: 25px;
background: linear-gradient(90deg, rgba(250, 174, 32, 0.30) -50%, rgba(102, 102, 102, 0.00) 100%);
box-shadow: 0 4px 4px 0  rgba(216, 72, 47, 0.40);
}

.room-card:nth-child(3) {
border-radius: 25px;
background: linear-gradient(90deg, rgba(243, 238, 76, 0.40) -50%, rgba(102, 102, 102, 0.00) 100%);
box-shadow: 0 4px 4px 0 rgba(242, 113, 0, 0.40);
}

.room-card:nth-child(4) {
border-radius: 25px;
background: linear-gradient(90deg, rgba(56, 64, 201, 0.60) -50%, rgba(102, 102, 102, 0.00) 100%);
box-shadow: 0 4px 4px 0 rgba(85, 27, 179, 0.40);
}

.room-card:nth-child(5) {
border-radius: 25px;
background: linear-gradient(90deg, rgba(78, 176, 241, 0.50) -50%, rgba(102, 102, 102, 0.00) 100%);
box-shadow: 0 4px 4px 0 rgba(56, 64, 201, 0.40);
}

.room-card:nth-child(6) {
border-radius: 25px;
background: linear-gradient(90deg, rgba(178, 0, 56, 0.40) -50%, rgba(102, 102, 102, 0.00) 100%);
box-shadow: 0 4px 4px 0 rgba(178, 0, 56, 0.40);
}

.room-card:nth-child(7) {
border-radius: 25px;
background: linear-gradient(90deg, rgba(250, 174, 32, 0.60) -50%, rgba(102, 102, 102, 0.00) 100%);
box-shadow: 0 4px 4px 0 rgba(230, 34, 0, 0.40);
}

.room-card:nth-child(8) {
border-radius: 25px;
background: linear-gradient(90deg, rgba(243, 238, 76, 0.50) -50%, rgba(102, 102, 102, 0.00) 100%);
box-shadow: 0 4px 4px 0 rgba(242, 113, 0, 0.40);
}



.room-header {
  display: flex;
  justify-content: space-between;
 
  align-items: flex-start;
  margin-bottom: 18px;
  height: 40px;
}

.room-icon-placeholder {
  position: absolute;
  display: flex;
  align-self: flex-start;
  padding: 10px;
  width: 100px;
  height: 100px;
  border-radius: 20px;
  background: lightgray;
}

.room-name {
  position: relative;
  left: 150px;
  top: 0px;
  font-size: 20px;
  font-weight: 600;
  font-family: Nunito, sans-serif;

}

.room-area {
  margin-top: 6px;
  color: #64748b;
}

.room-count {
  width: 120px;
  height: 45px;
  position: relative;
  font-size: 30px;
  color: #070707;
  font-weight: 900;
  text-align: center;
  top: 70px;
  right: 25px;
}

.room-count::after {
  content: "Kinder";
  position: absolute;
  font-size: 18px;
  font-weight: 800;
  color: #000000;
  bottom: -15px;
  left: 50%;
  transform: translateX(-50%);
}

.statusbar {
 width: 260px;
height: 45px;
  position: relative;
  display: flex;
  justify-self: center;
  align-self: center;
  bottom: -80px;
  border-radius: 18px;
background: #F5F5F5;
box-shadow: 0 4px 4px 0 rgba(0, 0, 0, 0.25) inset;
}

.satusfill {
  align-self: center;
  justify-self: flex-start;
  position: relative;
  left: 10px;
  height: 65%;
  width: 50%;
  border-radius: 12px;
  background: linear-gradient(90deg, rgba(56, 189, 248, 0.85), rgba(34, 197, 94, 0.85));
  border: 2px solid rgba(0, 125, 83, 0.40);
  transition: width 0.25s ease;
}

</style>
