<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useRoute } from "vue-router";
import { useTVRoomStore } from "@/stores/tvRoomStore";
import type { Room } from "@/stores/dashboardDataStore";


const store = useTVRoomStore();
const route = useRoute();
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

/* const capacityLabel = computed(() => {
  const capacity = room.value?.capacity;
  return typeof capacity === "number" ? String(capacity) : "-";
}); */

const connectionLabel = computed(() =>
  store.sseConnected ? "Live verbunden" : "Live Verbindung...",
);


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
  if (percentage < 45) {
    return { width: `${percentage}%`, background: "linear-gradient(90deg, rgba(56, 189, 248, 0.85), rgba(34, 197, 94, 0.85))" };
  } else if (percentage < 75) {
    return { width: `${percentage}%`, background: "linear-gradient(90deg, rgba(250, 174, 32, 0.85), rgba(243, 238, 76, 0.85))" };
  } else {
    return { width: `${percentage}%`, background: "linear-gradient(90deg, rgba(216, 72, 47, 0.85), rgba(178, 0, 56, 0.85))" };
  }
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
    <section class="room-view">
      
   <svg v-if="currentArea === 'EG_Außenbereich'" class="room-svg" xmlns="http://www.w3.org/2000/svg" width="700" height="199" viewBox="0 0 700 199" fill="none">
  <g filter="url(#filter0_di_55307_2)">
    <path d="M321 126C321 112.193 332.193 101 346 101H576.5C590.307 101 601.5 112.193 601.5 126V162C601.5 175.807 590.307 187 576.5 187H346C332.193 187 321 175.807 321 162V126Z" fill="#D8482F"/>
    <path d="M3 36C3 16.67 18.67 1 38 1H662C681.33 1 697 16.67 697 36V86C697 105.33 681.33 121 662 121H382C373.716 121 367 127.716 367 136V161.059C367 178.719 352.719 193.053 335.059 193.118L128.56 193.88C110.597 193.946 96 179.403 96 161.44V136C96 127.716 89.2843 121 81 121H38C18.67 121 3 105.33 3 86V36Z" fill="#F5F5F5"/>
  </g>
  <defs>
    <filter id="filter0_di_55307_2" x="0" y="0" width="700" height="198.88" filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
      <feFlood flood-opacity="0" result="BackgroundImageFix"/>
      <feColorMatrix in="SourceAlpha" type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0" result="hardAlpha"/>
      <feOffset dy="2"/>
      <feGaussianBlur stdDeviation="1.5"/>
      <feComposite in2="hardAlpha" operator="out"/>
      <feColorMatrix type="matrix" values="0 0 0 0 0.847059 0 0 0 0 0.282353 0 0 0 0 0.184314 0 0 0 0.4 0"/>
      <feBlend mode="normal" in2="BackgroundImageFix" result="effect1_dropShadow_55307_2"/>
      <feBlend mode="normal" in="SourceGraphic" in2="effect1_dropShadow_55307_2" result="shape"/>
      <feColorMatrix in="SourceAlpha" type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0" result="hardAlpha"/>
      <feOffset dy="4"/>
      <feGaussianBlur stdDeviation="2"/>
      <feComposite in2="hardAlpha" operator="arithmetic" k2="-1" k3="1"/>
      <feColorMatrix type="matrix" values="0 0 0 0 0.698039 0 0 0 0 0 0 0 0 0 0.219608 0 0 0 0.25 0"/>
      <feBlend mode="normal" in2="shape" result="effect2_innerShadow_55307_2"/>
    </filter>
  </defs>
</svg>

<svg v-if="currentArea === 'UG'" class="room-svg" xmlns="http://www.w3.org/2000/svg" width="700" height="191" viewBox="0 0 700 191" fill="none">
  <g filter="url(#filter0_di_55307_3)">
    <path d="M126 126C126 112.193 137.193 101 151 101H345C358.807 101 370 112.193 370 126V154C370 167.807 358.807 179 345 179H151C137.193 179 126 167.807 126 154V126Z" fill="#F27100"/>
    <path d="M3 36C3 16.67 18.67 1 38 1H662C681.33 1 697 16.67 697 36V86C697 105.33 681.33 121 662 121H621C612.716 121 606 127.716 606 136V153.059C606 170.719 591.719 185.053 574.059 185.118L367.56 185.88C349.597 185.946 335 171.403 335 153.44V136C335 127.716 328.284 121 320 121H38C18.67 121 3 105.33 3 86V36Z" fill="#F5F5F5"/>
  </g>
  <defs>
    <filter id="filter0_di_55307_3" x="0" y="0" width="700" height="190.88" filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
      <feFlood flood-opacity="0" result="BackgroundImageFix"/>
      <feColorMatrix in="SourceAlpha" type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0" result="hardAlpha"/>
      <feOffset dy="2"/>
      <feGaussianBlur stdDeviation="1.5"/>
      <feComposite in2="hardAlpha" operator="out"/>
      <feColorMatrix type="matrix" values="0 0 0 0 0.847059 0 0 0 0 0.282353 0 0 0 0 0.184314 0 0 0 0.4 0"/>
      <feBlend mode="normal" in2="BackgroundImageFix" result="effect1_dropShadow_55307_3"/>
      <feBlend mode="normal" in="SourceGraphic" in2="effect1_dropShadow_55307_3" result="shape"/>
      <feColorMatrix in="SourceAlpha" type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0" result="hardAlpha"/>
      <feOffset dy="4"/>
      <feGaussianBlur stdDeviation="2"/>
      <feComposite in2="hardAlpha" operator="arithmetic" k2="-1" k3="1"/>
      <feColorMatrix type="matrix" values="0 0 0 0 0.698039 0 0 0 0 0 0 0 0 0 0.219608 0 0 0 0.25 0"/>
      <feBlend mode="normal" in2="shape" result="effect2_innerShadow_55307_3"/>
    </filter>
  </defs>
</svg>
  
    <header class="header">
      <div class="subheader">
        <h1>Willkommen im Hort Pregarten!</h1>
        <section class="area-labels">
          <div class="room-area-eg" :class="{ active: currentArea === 'EG_Außenbereich' }">Erdgeschoss</div>
          <div class="room-area-og" :class="{ active: currentArea === 'UG' }">Obergeschoss</div>
        
        </section>
        
        <!-- <p class="subtitle">{{ roomCountLabel }}</p> -->
        
        
      </div>
      <span class="connection" :class="{ online: store.sseConnected }">
        {{ connectionLabel }}
      </span>
      <div class="search">
        <button class="search-button"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 30 30" fill="none">
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
        <input
          type="text"
          placeholder="Kinder suchen..."
        /></div>
    </header>

    <p v-if="store.loading" class="info">Lade Raumdaten...</p>
    <p v-else-if="store.error" class="error">{{ store.error }}</p>

    <section v-else class="room-grid">
      <article v-for="room in filteredRooms" :key="room.id" class="room-card">
        <div class="room-header">
          <div class="room-icon"></div>
            <div class="room-name">{{ room?.name }}</div>
            <!-- <div class="room-area">{{ room.area ?? "Bereich nicht verfügbar" }}</div>  -->
          
          <div class="room-count">
            {{ room.current_count ?? room.children?.length ?? 0 }} / {{ room.capacity ?? "-" }}
          </div>
        </div>

        <div class="statusbar">
          <div class="satusfill" :style="roomFillStyle(room)"></div>
        </div>

        <div class="children">
          <!-- <strong>Aktuelle Kinder</strong> -->
         <!--  <p v-if="!(room.children?.length)">Keine Kinder im Raum</p>
          <ul v-else class="child-list">
            <li v-for="child in room.children" :key="child.id" class="child-pill">
              {{ childInitials(child.name) }} 
            </li>
          </ul>-->
        </div>
      </article>
    </section>
  </section>
</template>

<style scoped>
.room-view {
  min-height: 100vh;
  padding: 24px;
  background: linear-gradient(180deg, #b4d2e8 0%, #d2b6e3 100%);
  color: #0f172a;
}

header {
  height: 55px;
  width: 100vw;
  position: relative;
  left: 50%;
  right: 50%;
  margin-left: -49vw;
  margin-right: -49vw;
 margin-bottom: 70px;
  color: black;
  padding: 28px 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.subheader {
  position: relative;
  left: -5px;
  top: 8px;
  display: flex;
  width: 690px;
  height: 110px;
  justify-content: center;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 24px;
    
}

.room-svg {
  position: absolute;
  left: 15px;
  top: 0px;
  width: 620px;
  height: 200px;
 
}

.header h1 {
  position: relative;
  left: -5px;
    color: #B20038;
font-family: Nunito;
font-size: 34px;
font-style: normal;
font-weight: 600;
line-height: normal;
}

.area-labels {
  position: relative;
  left: -30px;
  top: 5px;
  display: flex;
  gap: 20px;
}

.room-area-eg {
  position: relative;
  left: 0px;
  top: -5px;
  width: 195px;
height: 50px;
border-radius: 18px;
background: #F27100;
align-items: center;
justify-content: center;
display: flex;
color: white;
font-size: 24px;
font-weight: 700;
transition: all 180ms ease-in-out;
}

.room-area-og {
    position: relative;
  left: 20px;
  top: 5px;
  font-size: 18px;
  font-weight: 600;
  width: 160px;
height: 40px;
border-radius: 15px;
background: white;
align-items: center;
justify-content: center;
display: flex;
color: #D8482F;
transition: all 180ms ease-in-out;
}

/* .room-area-eg.active {
  transform: scale(1.02);
  box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.45);
}
 */
.room-area-eg:not(.active) {
    left: 15px;
  top: 3px;
   font-size: 18px;
  font-weight: 600;
  width: 145px;
height: 40px;
color: #D8482F;
background: white;
}

.room-area-og.active {
  left: 35px;
  top: -5px;
  font-size: 24px;
  font-weight: 700;
  color: white;
  background: #D8482F;
  width: 195px;
height: 50px;
 
}


.subtitle {
  
  margin: 4px 0 0;
  color: #475569;
  font-size: 1rem;
}

.connection {
  position: absolute;
  right: 10px;
  top: 0px;
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

.search {
  position: relative;
  display: inline;
  right: 45px;
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 250px;
 width: 340px;
  height: 38px;
  border-radius: 15px;
  
  background: #F5F5F5;
/* element_shadow_blue */
  box-shadow: 0 4px 4px 0 rgba(85, 27, 179, 0.25) inset, 0 4px 4px 0 rgba(56, 64, 201, 0.25);
  display: flex;
  align-items: center;
  padding: 4px 8px;
}

.search input {
  border: none;
  background: transparent;
  outline: none;
  font-family: Nunito, sans-serif;
  font-size: 1rem;
}

.search button {
  border: none;
  background: transparent;
  cursor: pointer;
  font-size: 1.2rem;
}

.search svg {
  width: 20px;
  height: 20px;
  top: 5px;
  fill: #3840C9;
  opacity: 0.8;
  
}



.room-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
  gap: 22px;
  background: rgba(245, 245, 245, 0.80);
  border-radius: 16px;
  padding: 18px;
}

.room-card {
 /*  background: #ffffff; 
  opacity: 80%;
  border-radius: 24px;
  padding: 24px;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08); */
  width: 450px;
  height: 136.401px;
  border-radius: 25px;
}

.room-card:nth-child(1) {
  background: linear-gradient(90deg, rgba(99, 184, 82, 0.50) 0%, rgba(102, 102, 102, 0.00) 79.7%), #007D53;
background-blend-mode: screen, normal;
box-shadow: 0 4px 4px 0 rgba(0, 125, 83, 0.40);
}

.room-card:nth-child(2) {
background: linear-gradient(90deg, rgba(78, 176, 241, 0.50) 0%, rgba(102, 102, 102, 0.00) 79.7%), #3840C9;
background-blend-mode: screen, normal;
box-shadow: 0 4px 4px 0 rgba(0, 0, 0, 0.25);
}

.room-card:nth-child(3n+8) {
background: linear-gradient(90deg, rgba(243, 238, 76, 0.40) 0%, rgba(102, 102, 102, 0.00) 79.7%), #F27100;
background-blend-mode: screen, normal;
box-shadow: 0 4px 4px 0 rgba(0, 0, 0, 0.25);
}

.room-card:nth-child(4) {
background: linear-gradient(90deg, rgba(56, 64, 201, 0.60) 0%, rgba(102, 102, 102, 0.00) 79.7%), #551BB3;
background-blend-mode: screen, normal;
box-shadow: 0 4px 4px 0 rgba(0, 0, 0, 0.25);
}

.room-card:nth-child(5n+7) {
background: linear-gradient(90deg, rgba(250, 174, 32, 0.50) 0%, rgba(102, 102, 102, 0.00) 79.7%), #E62200;
background-blend-mode: screen, normal;
box-shadow: 0 4px 4px 0 rgba(0, 0, 0, 0.25);
}

.room-card:nth-child(6) {
background: linear-gradient(90deg, rgba(242, 113, 0, 0.40) 0%, rgba(102, 102, 102, 0.00) 79.7%), #B20038;
background-blend-mode: screen, normal;
box-shadow: 0 4px 4px 0 rgba(0, 0, 0, 0.25);
}





.room-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 20px;
  margin-bottom: 18px;
  
}

.room-name {
  position: relative;
  left: 18px;
  top: 8px;
  font-size: 18px;
  font-weight: 700;
  font-family: Nunito, sans-serif;
  color: white;
}

.room-area {
  margin-top: 6px;
  
}

.room-count {
  /* align-self: flex-end;
  justify-self: end; */
  position: relative;
  font-size: 30px;
  color: #f4f4f6;
  font-weight: 500;
  text-align: right;
  top: 18px;
  right: 18px;
}

.room-count::after {
  content: " Kinder";
  font-size: 16px;
  color: #f4f4f6;
}

.statusbar {
  width: 315.11px;
height: 39.964px;
  position: relative;
  display: inline-block;
  left: 55px;
  bottom: -20px;
  border-radius: 20px;
background: #F5F5F5;
box-shadow: 0 4px 4px 0 rgba(0, 0, 0, 0.25) inset;
}

.satusfill {
  height: 85%;
  border-radius: 20px;
  left: 5px;
  top: 3px;
  position: relative;
  max-width: 310px;
  background: linear-gradient(90deg, rgba(56, 189, 248, 0.85), rgba(34, 197, 94, 0.85));
  transition: width 0.25s ease;
}


</style>
