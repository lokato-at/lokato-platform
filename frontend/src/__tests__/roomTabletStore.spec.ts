import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('../api/axios', () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import { useRoomTabletStore } from '@/stores/roomTabletStore'

describe('roomTabletStore.handleOccupancyUpdate', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('replaces the snapshot with capacity, tolerance and status from the SSE payload', () => {
    // Drift-Regression: Damit der Status-Pill und der count-color des Tablets
    // live einfaerben koennen, MUSS handleOccupancyUpdate capacity/tolerance/status
    // in snapshot.room mergen.
    const store = useRoomTabletStore()
    store.roomId = 3
    store.snapshot = {
      room: { id: 3, name: 'Garten', capacity: 5, tolerance: 2 },
      current_count: 2,
      children: [],
    }

    store.handleOccupancyUpdate({
      room_id: 3,
      room_name: 'Garten',
      capacity: 3,
      tolerance: 1,
      current_count: 5,
      children: [{ id: 1, name: 'Kind A' }],
      status: { over_capacity: true, within_tolerance: false },
    })

    expect(store.snapshot.room?.capacity).toBe(3)
    expect(store.snapshot.room?.tolerance).toBe(1)
    expect(store.snapshot.room?.status?.over_capacity).toBe(true)
    expect(store.snapshot.room?.status?.within_tolerance).toBe(false)
    expect(store.snapshot.current_count).toBe(5)
    expect(store.snapshot.children).toHaveLength(1)
  })

  it('ignores updates for a different room (scoped tablet must not display foreign data)', () => {
    const store = useRoomTabletStore()
    store.roomId = 3
    store.snapshot = {
      room: { id: 3, name: 'Garten' },
      current_count: 1,
      children: [{ id: 99, name: 'Existing' }],
    }

    store.handleOccupancyUpdate({
      room_id: 7,
      current_count: 99,
      children: [{ id: 1, name: 'Wrong room' }],
    })

    expect(store.snapshot.current_count).toBe(1)
    expect(store.snapshot.children[0]!.id).toBe(99)
  })

  it('preserves existing room metadata when payload omits capacity/tolerance/status', () => {
    const store = useRoomTabletStore()
    store.roomId = 1
    store.snapshot = {
      room: { id: 1, name: 'Raum', capacity: 10, tolerance: 3 },
      current_count: 0,
      children: [],
    }

    store.handleOccupancyUpdate({
      room_id: 1,
      current_count: 2,
      children: [{ id: 1, name: 'A' }, { id: 2, name: 'B' }],
    })

    expect(store.snapshot.room?.capacity).toBe(10)
    expect(store.snapshot.room?.tolerance).toBe(3)
    expect(store.snapshot.current_count).toBe(2)
  })
})
