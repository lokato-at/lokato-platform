// src/stores/roomStore.ts
import { defineStore } from 'pinia'
import axios from 'axios'

// 🔹 Admin-API Basis
const BASE_URL = 'http://localhost:8001/api/v1/admin'

// ----------------------------
// Typdefinition für Räume
// ----------------------------
export interface Room {
  id: number
  name: string
  area?: string
  capacity: number
  tolerance?: number
  is_active?: boolean
}

// ----------------------------
// Pinia Store
// ----------------------------
export const useRoomStore = defineStore('roomStore', {
  state: () => ({
    rooms: [] as Room[],
    loading: false,
    error: null as string | null
  }),

  actions: {
    // Alle Räume abrufen
    async fetchRooms(): Promise<void> {
      this.loading = true
      this.error = null
      try {
        const res = await axios.get<Room[]>(`${BASE_URL}/rooms`)
        this.rooms = res.data
      } catch (err) {
        if (axios.isAxiosError(err) && err.response) {
          this.error = `Error ${err.response.status}: ${err.response.statusText}`
        } else if (err instanceof Error) {
          this.error = err.message
        } else {
          this.error = String(err)
        }
      } finally {
        this.loading = false
      }
    },

    // Kapazität eines Raumes ändern
    async updateRoomCapacity(id: number, capacity: number): Promise<void> {
      this.loading = true
      this.error = null
      try {
        const res = await axios.patch<Room>(`${BASE_URL}/rooms/${id}`, { capacity })
        const room = this.rooms.find(r => r.id === id)
        if (room) room.capacity = res.data.capacity
      } catch (err) {
        if (axios.isAxiosError(err) && err.response) {
          this.error = `Error ${err.response.status}: ${err.response.statusText}`
        } else if (err instanceof Error) {
          this.error = err.message
        } else {
          this.error = String(err)
        }
      } finally {
        this.loading = false
      }
    },

    // Raum aktiv/deaktivieren
    async toggleRoomActive(id: number, is_active: boolean): Promise<void> {
      this.loading = true
      this.error = null
      try {
        const res = await axios.patch<Room>(`${BASE_URL}/rooms/${id}`, { is_active })
        const room = this.rooms.find(r => r.id === id)
        if (room) room.is_active = res.data.is_active
      } catch (err) {
        if (axios.isAxiosError(err) && err.response) {
          this.error = `Error ${err.response.status}: ${err.response.statusText}`
        } else if (err instanceof Error) {
          this.error = err.message
        } else {
          this.error = String(err)
        }
      } finally {
        this.loading = false
      }
    },

    // Name und Bereich eines Raumes ändern
    async updateRoomDetails(id: number, data: Partial<Pick<Room, 'name' | 'area'>>): Promise<void> {
      this.loading = true
      this.error = null
      try {
        const res = await axios.patch<Room>(`${BASE_URL}/rooms/${id}`, data)
        const room = this.rooms.find(r => r.id === id)
        if (room) {
          room.name = res.data.name
          room.area = res.data.area
        }
      } catch (err) {
        if (axios.isAxiosError(err) && err.response) {
          this.error = `Error ${err.response.status}: ${err.response.statusText}`
        } else if (err instanceof Error) {
          this.error = err.message
        } else {
          this.error = String(err)
        }
      } finally {
        this.loading = false
      }
    }
  }
})
