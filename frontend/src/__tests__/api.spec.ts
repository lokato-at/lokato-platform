import { describe, expect, it, vi, beforeEach } from 'vitest'

// import.meta.env.VITE_API_BASE_URL muss vor dem Import gemockt werden,
// weil das Modul den Wert in eine Modul-Konstante einliest.
vi.mock('import.meta.env', () => ({ VITE_API_BASE_URL: '/api/v1' }))

describe('buildApiUrl', () => {
  let buildApiUrl: (path: string) => string

  beforeEach(async () => {
    vi.resetModules()
    const mod = await import('@/utils/api')
    buildApiUrl = mod.buildApiUrl
  })

  it('prefixes a relative path with the configured base url', () => {
    expect(buildApiUrl('rooms')).toBe('/api/v1/rooms')
    expect(buildApiUrl('/rooms')).toBe('/api/v1/rooms')
  })

  it('strips the /v1 suffix for /stream paths because SSE lives directly under /api', () => {
    // Stream-Pfade liegen unter /api/, nicht unter /api/v1/.
    expect(buildApiUrl('/stream')).toBe('/api/stream')
    expect(buildApiUrl('/stream?room=3')).toBe('/api/stream?room=3')
    expect(buildApiUrl('/stream/foo')).toBe('/api/stream/foo')
  })

  it('does NOT match /stream-foo (word boundary in the regex)', () => {
    // Regression test gegen falsches Greedy-Matching, das /stream-foo
    // ebenfalls als Stream-Pfad behandeln würde.
    expect(buildApiUrl('/stream-foo')).toBe('/api/v1/stream-foo')
  })

  it('returns absolute URLs as-is', () => {
    expect(buildApiUrl('https://example.com/api/v1/rooms')).toBe(
      'https://example.com/api/v1/rooms',
    )
  })
})
