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

const ShowOG = computed(() =>
  

)

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

      <svg xmlns="http://www.w3.org/2000/svg" width="906" height="208" viewBox="0 0 906 208" fill="none">
  <g filter="url(#filter0_di_55369_4)">
    <path d="M3 36C3 16.6701 18.67 1 38 1H868C887.33 1 903 16.67 903 36V93.8534C903 113.132 887.41 128.781 868.131 128.853L618.496 129.787C609.662 129.787 602.5 136.949 602.5 145.783V168C602.5 187.33 586.83 203 567.5 203H338.236C318.814 203 303.106 187.186 303.237 167.764L303.392 144.718C303.452 135.902 296.321 128.723 287.504 128.723H38C18.6701 128.723 3 113.053 3 93.7228V36Z" fill="#F5F5F5"/>
  </g>
  <defs>
    <filter id="filter0_di_55369_4" x="0" y="0" width="906" height="208" filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
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
          <div class="room-area-eg">Erdgeschoss</div>
          <div v-show="ShowOG" class="room-area-og">Obergeschoss</div>

        </section>
      </div>
      <!-- <span class="connection" :class="{ online: store.sseConnected }">
        {{ connectionLabel }}
      </span> -->
    </header>

    <p v-if="store.loading" class="info">Lade Raumdaten...</p>
    <p v-else-if="store.error" class="error">{{ store.error }}</p>

    <div v-else class="room-grid">


      <article v-for="room in rooms" :key="room.id" class="room-card">
        <div class="room-header">
          <div class="room-icon"></div>
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
    top: 0px;
   right: 0px;
  justify-content: center;
  align-items: center;
  flex-wrap: wrap;
  gap: 3px;
  margin-bottom: 26px;

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
font-size: 40px;
font-style: normal;
font-weight: 500;
line-height: normal;
}



.area-labels {

  display: flex;
  justify-content: center;
  align-items: center;

}

.room-area-eg {

  width: 250px;
height:55px;
border-radius: 18px;
background: #F27100;
align-items: center;
justify-content: center;
display: flex;
color: white;
font-size: 24px;
font-weight: 700;
}

.room-area-og {
  font-size: 24px;
  font-weight: 700;
  width: 250px;
height: 55px;
border-radius: 18px;
background: white;
align-items: center;
justify-content: center;
display: flex;
color: #D8482F;
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
  width: 350;
  height: 185px;
  background: #ffffff;
  border-radius: 24px;
  padding: 24px;

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
  gap: 16px;
  margin-bottom: 18px;
}

.room-name {
  position: relative;
  left: 30px;
  top: 0px;
  font-size: 24px;
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
  right: 20px;
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
/*
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
 */
/* .child-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 40px;
  min-height: 40px;
  border-radius: 999px;
  background: #f8fafc;
  color: #0f172a;
  font-weight: 700;
} */
</style>
