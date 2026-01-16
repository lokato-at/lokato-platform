<script setup lang="ts">
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminChild } from "@/stores/adminDataStore";

const store = useAdminDataStore();

function remove(child: AdminChild) {
  if (!child.id) return;
  store.deleteChild(child.id);
}
</script>

<template>
  <div>
    <h2>Kinder</h2>

    <ul>
      <li v-for="child in store.children" :key="child.id">
        {{ child.name }}
        <button @click="remove(child)">Löschen</button>
      </li>
    </ul>
  </div>
</template>
