<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Child } from '@/stores/dashboardDataStore'

const props = defineProps<{
  child: Child
  size?: 'sm' | 'md' | 'lg'
}>()

// Foto-Auswahl in dieser Reihenfolge:
//   1) /branding/children/<child-id>.jpg          (Convention, manuell abgelegt)
//   2) child.photo_url                            (falls in DB gesetzt)
//   3) Initial-Placeholder
//
// Bei 404 auf #1 fällt das <img> automatisch auf die nächste Quelle.
// Tipp im branding/README.md: legt die Bilder als z.B. "1.jpg", "2.jpg" ab —
// die child.id ist die stabile ID, die im Admin-Bereich angezeigt wird.

const candidates = computed<string[]>(() => {
  const list: string[] = []
  // 1) DB-photo_url (vom Admin via Upload gesetzt)
  if (props.child.photo_url) {
    list.push(props.child.photo_url)
  }
  // 2) Convention-Fallback: manuell abgelegte Datei
  if (props.child.id != null) {
    list.push(`/branding/children/${props.child.id}.jpg`)
  }
  return list
})

const currentIndex = ref(0)
// Bei Kind-Wechsel (z.B. neue Position im List-Render) Index resetten.
watch(
  () => props.child.id,
  () => {
    currentIndex.value = 0
  },
)

const currentSrc = computed(() => candidates.value[currentIndex.value] ?? null)

function onError() {
  // Nächste Quelle probieren. Wenn keine mehr da: out-of-range setzt currentSrc=null.
  currentIndex.value += 1
}

function initial(name?: string): string {
  return (name ?? '?').trim().charAt(0).toUpperCase()
}
</script>

<template>
  <span class="child-badge" :class="`size-${size ?? 'md'}`">
    <img
      v-if="currentSrc"
      :src="currentSrc"
      :alt="`Foto von ${child.name}`"
      class="avatar"
      @error="onError"
    />
    <span v-else class="avatar placeholder">{{ initial(child.name) }}</span>
    <span class="name">{{ child.name }}</span>
  </span>
</template>

<style scoped>
.child-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.avatar {
  border-radius: 999px;
  object-fit: cover;
  flex-shrink: 0;
}

.size-sm .avatar { width: 28px; height: 28px; }
.size-md .avatar { width: 36px; height: 36px; }
.size-lg .avatar { width: 48px; height: 48px; }

.avatar.placeholder {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #e2e8f0;
  color: #334155;
  font-weight: 700;
  font-size: 0.95rem;
}

.name {
  font-weight: 500;
}
</style>
