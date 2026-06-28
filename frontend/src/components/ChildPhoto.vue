<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Child } from '@/stores/dashboardDataStore'

const props = defineProps<{
  child: Child
  /** CSS-Größe (z.B. "48px"). Default 48px — wird vom Parent über Style überschrieben. */
  size?: string
}>()

// Identische Logik wie in ChildBadge, aber ohne Namen — geeignet für
// größere quadratische Avatare in Tablet-/Modal-Listen.

const candidates = computed<string[]>(() => {
  const list: string[] = []
  // 1) DB-photo_url hat hoechste Prioritaet (vom Admin via Upload gesetzt
  //    → /storage/children/<id>.<ext>, oder externe URL).
  if (props.child.photo_url) {
    list.push(props.child.photo_url)
  }
  // 2) Convention-Fallback: manuell abgelegte Datei in branding/children/<id>.jpg.
  //    Greift wenn photo_url leer ist ODER 404 zurueckkommt.
  if (props.child.id != null) {
    list.push(`/branding/children/${props.child.id}.jpg`)
  }
  return list
})

const currentIndex = ref(0)
watch(
  () => props.child.id,
  () => {
    currentIndex.value = 0
  },
)

const currentSrc = computed(() => candidates.value[currentIndex.value] ?? null)

function onError() {
  currentIndex.value += 1
}

function initial(name?: string): string {
  return (name ?? '?').trim().charAt(0).toUpperCase()
}

const sizeStyle = computed(() => ({
  width: props.size ?? '48px',
  height: props.size ?? '48px',
}))
</script>

<template>
  <img
    v-if="currentSrc"
    :src="currentSrc"
    :alt="`Foto von ${child.name}`"
    class="child-photo"
    :style="sizeStyle"
    @error="onError"
  />
  <span
    v-else
    class="child-photo placeholder"
    :style="sizeStyle"
  >
    {{ initial(child.name) }}
  </span>
</template>

<style scoped>
.child-photo {
  border-radius: 999px;
  object-fit: cover;
  flex-shrink: 0;
}

.child-photo.placeholder {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #e2e8f0;
  color: #334155;
  font-weight: 700;
  font-size: 1rem;
}
</style>
