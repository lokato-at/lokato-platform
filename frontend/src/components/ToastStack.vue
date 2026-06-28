<script setup lang="ts">
import { useToast } from '@/composables/useToast'

const { toasts } = useToast()
</script>

<template>
  <Teleport to="body">
    <div class="toast-stack" aria-live="polite">
      <TransitionGroup name="toast">
        <div
          v-for="t in toasts"
          :key="t.id"
          class="toast"
          :class="t.type"
          role="status"
        >
          {{ t.message }}
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-stack {
  position: fixed;
  top: 20px;
  right: 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  z-index: 3000;
  pointer-events: none;
}

.toast {
  background: #1e293b;
  color: white;
  padding: 12px 18px;
  border-radius: 10px;
  font-family: Nunito, sans-serif;
  font-weight: 600;
  font-size: 0.95rem;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
  min-width: 240px;
  max-width: 360px;
  pointer-events: auto;
}

.toast.success { background: #16a34a; }
.toast.error   { background: #dc2626; }
.toast.info    { background: #2563eb; }

.toast-enter-active, .toast-leave-active {
  transition: all 0.25s ease;
}
.toast-enter-from {
  opacity: 0;
  transform: translateX(40px);
}
.toast-leave-to {
  opacity: 0;
  transform: translateX(40px);
}

@media (max-width: 700px) {
  .toast-stack { top: 10px; right: 10px; left: 10px; }
  .toast { min-width: 0; max-width: none; }
}
</style>
