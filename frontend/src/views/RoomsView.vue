<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoomStore, Room } from '../stores/roomStore'

const roomStore = useRoomStore()
const editRoomId = ref<number | null>(null)
const editedName = ref('')
const editedArea = ref('')
const editedCapacity = ref(0)

// Räume beim Laden holen
onMounted(() => {
  roomStore.fetchRooms()
})

// Bearbeitung starten
function startEdit(room: Room) {
  editRoomId.value = room.id
  editedName.value = room.name
  editedArea.value = room.area || ''
  editedCapacity.value = room.capacity
}

// Bearbeitung speichern
async function saveEdit() {
  if (editRoomId.value === null) return
  await roomStore.updateRoomDetails(editRoomId.value, {
    name: editedName.value,
    area: editedArea.value
  })
  await roomStore.updateRoomCapacity(editRoomId.value, editedCapacity.value)
  editRoomId.value = null
}

// Aktiv/Inaktiv toggle
function toggleActive(room: Room) {
  roomStore.toggleRoomActive(room.id, !room.is_active)
}
</script>

<template>
  <div class="rooms-container">
    <h1>Testumgebung</h1>


<!--    Untergeschoss und Obergeschoss nur zu bestimmten Zeiten aktiv-->

    <div v-if="roomStore.loading" class="loading">Lädt...</div>
    <div v-if="roomStore.error" class="error">{{ roomStore.error }}</div>

    <table v-if="roomStore.rooms.length" class="rooms-table">
      <thead>
      <tr>
        <th>Name</th>
        <th>Bereich</th>
        <th>Kapazität</th>
        <th>Aktiv</th>
        <th>Aktionen</th>
      </tr>
      </thead>
      <tbody>
      <tr v-for="room in roomStore.rooms" :key="room.id">
        <td>
          <input v-if="editRoomId === room.id" v-model="editedName" />
          <span v-else>{{ room.name }}</span>
        </td>
        <td>
          <input v-if="editRoomId === room.id" v-model="editedArea" />
          <span v-else>{{ room.area }}</span>
        </td>
        <td>
          <input
            type="number"
            min="0"
            v-if="editRoomId === room.id"
            v-model.number="editedCapacity"
          />
          <span v-else>{{ room.capacity }}</span>
        </td>
        <td>
          <input
            type="checkbox"
            :checked="room.is_active"
            @change="toggleActive(room)"
          />
        </td>
        <td>
          <button v-if="editRoomId === room.id" @click="saveEdit">Speichern</button>
          <button v-else @click="startEdit(room)">Bearbeiten</button>
        </td>
      </tr>
      </tbody>
    </table>

    <div v-else>
      Keine Räume gefunden.
    </div>
  </div>
</template>

<style scoped>
.rooms-container {
  max-width: 900px;
  margin: 2rem auto;
  font-family: Arial, sans-serif;
  padding: 1rem;
  background: #f8f9fa;
  border-radius: 8px;
  box-shadow: 0 0 10px rgba(0, 0, 0, 0.05);
}

h1 {
  text-align: center;
  margin-bottom: 1.5rem;
}

.rooms-table {
  width: 100%;
  border-collapse: collapse;
}

.rooms-table th,
.rooms-table td {
  border: 1px solid #dee2e6;
  padding: 0.5rem 1rem;
  text-align: left;
}

.rooms-table th {
  background-color: #343a40;
  color: #fff;
}

.rooms-table input[type='text'],
.rooms-table input[type='number'] {
  width: 100%;
  box-sizing: border-box;
  padding: 0.25rem;
  border-radius: 4px;
  border: 1px solid #ced4da;
}

.rooms-table input[type='checkbox'] {
  transform: scale(1.2);
}

button {
  padding: 0.3rem 0.6rem;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  margin-right: 0.3rem;
}

button:hover {
  opacity: 0.9;
}

button:active {
  transform: scale(0.98);
}

button:nth-child(1) {
  background-color: #28a745;
  color: #fff;
}

button:nth-child(2) {
  background-color: #007bff;
  color: #fff;
}

.loading {
  text-align: center;
  font-weight: bold;
  margin-bottom: 1rem;
}

.error {
  color: red;
  text-align: center;
  margin-bottom: 1rem;
}
</style>
