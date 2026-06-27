<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from 'vue'

interface Props {
  modelValue: boolean
  title?: string
  message?: string
  confirmLabel?: string
  cancelLabel?: string
  variant?: 'default' | 'danger'
  busy?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  title: 'Bestätigen',
  message: 'Möchten Sie diese Aktion wirklich ausführen?',
  confirmLabel: 'Bestätigen',
  cancelLabel: 'Abbrechen',
  variant: 'default',
  busy: false,
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'confirm'): void
  (e: 'cancel'): void
}>()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

function onCancel() {
  if (props.busy) return
  visible.value = false
  emit('cancel')
}

function onConfirm() {
  if (props.busy) return
  emit('confirm')
}

function handleEscape(e: KeyboardEvent) {
  if (e.key === 'Escape' && visible.value && !props.busy) {
    onCancel()
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleEscape)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleEscape)
})

// Lock body scroll while dialog is open so the page behind doesn't scroll.
watch(visible, (isOpen) => {
  if (typeof document === 'undefined') return
  document.body.style.overflow = isOpen ? 'hidden' : ''
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="visible"
      class="confirm-backdrop"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="`confirm-title-${(visible as unknown as number)}`"
      @click.self="onCancel"
    >
      <div class="confirm-card">
        <h3 :id="`confirm-title-${(visible as unknown as number)}`" class="confirm-title">
          {{ title }}
        </h3>
        <p class="confirm-message">
          <slot>{{ message }}</slot>
        </p>

        <div class="confirm-actions">
          <button
            type="button"
            class="btn btn-cancel"
            :disabled="busy"
            @click="onCancel"
          >
            {{ cancelLabel }}
          </button>
          <button
            type="button"
            class="btn"
            :class="variant === 'danger' ? 'btn-danger' : 'btn-primary'"
            :disabled="busy"
            @click="onConfirm"
          >
            {{ busy ? '…' : confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.confirm-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  z-index: 2000;
  animation: fadeIn 0.12s ease-out;
}

.confirm-card {
  background: white;
  border-radius: 14px;
  padding: 24px;
  max-width: 440px;
  width: 100%;
  box-shadow: 0 16px 48px rgba(0, 0, 0, 0.25);
  text-align: left;
  font-family: Nunito, "Segoe UI", sans-serif;
}

.confirm-title {
  margin: 0 0 12px;
  font-size: 1.25rem;
  font-weight: 700;
  color: #0f172a;
}

.confirm-message {
  margin: 0 0 24px;
  font-size: 1rem;
  color: #334155;
  line-height: 1.5;
}

.confirm-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

.btn {
  font-family: inherit;
  font-size: 1rem;
  font-weight: 600;
  padding: 9px 18px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-cancel {
  background: #e2e8f0;
  color: #334155;
}

.btn-cancel:hover:not(:disabled) {
  background: #cbd5e1;
}

.btn-primary {
  background: #2A7CD9;
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: #1d4ed8;
}

.btn-danger {
  background: #dc2626;
  color: white;
}

.btn-danger:hover:not(:disabled) {
  background: #b91c1c;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to   { opacity: 1; }
}
</style>
