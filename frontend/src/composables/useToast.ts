import { ref } from 'vue'

export type ToastType = 'success' | 'error' | 'info'

export interface Toast {
  id: number
  message: string
  type: ToastType
}

// Globale Toast-Liste — singleton, weil mehrere Komponenten in den gleichen
// Stack pushen sollen.
const toasts = ref<Toast[]>([])
let nextId = 0

function push(message: string, type: ToastType, durationMs: number): void {
  const id = ++nextId
  toasts.value.push({ id, message, type })
  setTimeout(() => {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }, durationMs)
}

export function useToast() {
  return {
    toasts,
    success: (message: string, durationMs = 2500) => push(message, 'success', durationMs),
    error: (message: string, durationMs = 4000) => push(message, 'error', durationMs),
    info: (message: string, durationMs = 2500) => push(message, 'info', durationMs),
  }
}
