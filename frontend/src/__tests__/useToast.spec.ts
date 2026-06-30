import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useToast } from '@/composables/useToast'

describe('useToast', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    // Stack zwischen Tests leeren (Singleton-State).
    useToast().toasts.value = []
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('adds a success toast and removes it after the default duration (2500 ms)', () => {
    const { toasts, success } = useToast()
    success('saved')

    expect(toasts.value).toHaveLength(1)
    expect(toasts.value[0]).toMatchObject({ message: 'saved', type: 'success' })

    vi.advanceTimersByTime(2500)
    expect(toasts.value).toHaveLength(0)
  })

  it('error toasts default to 4000 ms duration (longer than success)', () => {
    const { toasts, error } = useToast()
    error('boom')

    // success-Default 2500 ms muss schon abgelaufen sein, der Error-Toast aber
    // noch da — er soll laenger sichtbar bleiben.
    vi.advanceTimersByTime(2500)
    expect(toasts.value).toHaveLength(1)

    // erst nach 4000 ms verschwindet er
    vi.advanceTimersByTime(1500)
    expect(toasts.value).toHaveLength(0)
  })

  it('stacks multiple toasts and removes them independently by id', () => {
    const { toasts, success, error } = useToast()
    success('A', 1000)
    error('B', 3000)
    expect(toasts.value).toHaveLength(2)

    vi.advanceTimersByTime(1000)
    expect(toasts.value).toHaveLength(1)
    expect(toasts.value[0]!.message).toBe('B')

    vi.advanceTimersByTime(2000)
    expect(toasts.value).toHaveLength(0)
  })
})
