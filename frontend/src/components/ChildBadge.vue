<script setup lang="ts">
import type { Child } from '@/stores/dashboardDataStore'

defineProps<{
  child: Child
  size?: 'sm' | 'md' | 'lg'
}>()

function initial(name?: string): string {
  return (name ?? '?').trim().charAt(0).toUpperCase()
}
</script>

<template>
  <span class="child-badge" :class="`size-${size ?? 'md'}`">
    <img
      v-if="child.photo_url"
      :src="child.photo_url"
      :alt="`Foto von ${child.name}`"
      class="avatar"
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
