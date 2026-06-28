import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

// axios mocken bevor der Store importiert wird — der Store baut beim Import
// nichts auf, aber die Default-Exports brauchen wir trotzdem stubbed.
vi.mock('../api/axios', () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import { useDashboardDataStore } from '@/stores/dashboardDataStore'

describe('dashboardDataStore.handleOccupancyUpdate', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('updates capacity, tolerance, current_count and status on the room from the SSE payload', () => {
    // Drift-Regression: SSE-Payload muss live in den Room durchschlagen,
    // damit Dashboard-Warnungen/Ueberbelegt-Zaehler ohne F5 stimmen.
    const store = useDashboardDataStore()
    store.rooms = [
      { id: 7, name: 'Bastelraum', capacity: 5, tolerance: 2, current_count: 3 },
    ]

    store.handleOccupancyUpdate({
      room_id: 7,
      capacity: 3,
      tolerance: 1,
      current_count: 5,
      children: [],
      status: { over_capacity: true, within_tolerance: false },
    })

    const room = store.rooms?.find((r) => r.id === 7)
    expect(room?.capacity).toBe(3)
    expect(room?.tolerance).toBe(1)
    expect(room?.current_count).toBe(5)
    expect(room?.status?.over_capacity).toBe(true)
    expect(room?.status?.within_tolerance).toBe(false)
  })

  it('also updates the keyed occupancy snapshot, not just the rooms list', () => {
    const store = useDashboardDataStore()
    store.rooms = [{ id: 3, name: 'Garten', capacity: 10 }]
    store.occupancy = {}

    store.handleOccupancyUpdate({
      room_id: 3,
      capacity: 10,
      tolerance: 2,
      current_count: 4,
      children: [{ id: 1, name: 'Kind A' }],
      status: { over_capacity: false, within_tolerance: false },
    })

    expect(store.occupancy[3]).toBeDefined()
    expect(store.occupancy[3].current_count).toBe(4)
    expect(store.occupancy[3].children).toHaveLength(1)
  })

  it('falls back to children.length when current_count is omitted', () => {
    const store = useDashboardDataStore()
    store.rooms = [{ id: 1, name: 'R' }]

    store.handleOccupancyUpdate({
      room_id: 1,
      children: [
        { id: 1, name: 'A' },
        { id: 2, name: 'B' },
      ],
    })

    expect(store.occupancy[1].current_count).toBe(2)
  })
})

describe('dashboardDataStore.handleRoomStatusUpdate', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('merges payload into the existing room (preserves children/current_count from occupancy)', () => {
    const store = useDashboardDataStore()
    store.rooms = [
      { id: 1, name: 'Alt', capacity: 5, current_count: 3, children: [{ id: 1, name: 'X' }] },
    ]

    store.handleRoomStatusUpdate({ id: 1, name: 'Neu', capacity: 8, is_active: false })

    const room = store.rooms?.find((r) => r.id === 1)
    expect(room?.name).toBe('Neu')
    expect(room?.capacity).toBe(8)
    expect(room?.is_active).toBe(false)
    // children + current_count duerfen vom merge NICHT geloescht werden,
    // weil sie aus dem getrennten room.occupancy.updated-Event kommen.
    expect(room?.current_count).toBe(3)
    expect(room?.children).toHaveLength(1)
  })

  it('appends the room if it was not previously known (new room created via Admin live)', () => {
    const store = useDashboardDataStore()
    store.rooms = [{ id: 1, name: 'Bestehend' }]

    store.handleRoomStatusUpdate({ id: 99, name: 'Neuer Raum', capacity: 6, is_active: true })

    expect(store.rooms).toHaveLength(2)
    expect(store.rooms?.find((r) => r.id === 99)).toBeTruthy()
  })
})
