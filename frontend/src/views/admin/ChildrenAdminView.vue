<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminChild } from "@/stores/adminDataStore";

type ActiveFilter = "all" | "active" | "inactive";

const store = useAdminDataStore();
const searchTerm = ref("");
const activeFilter = ref<ActiveFilter>("all");

const normalizedSearch = computed(() => searchTerm.value.trim().toLowerCase());

const supportsIsActive = computed(() =>
  store.children.some((child) => typeof child.is_active === "boolean"),
);

const filteredChildren = computed<AdminChild[]>(() => {
  const query = normalizedSearch.value;

  return store.children.filter((child) => {
    const name = child.name?.toLowerCase() ?? "";
    const tracker = child.tracker_uid?.toLowerCase() ?? "";
    const matchesSearch = !query || name.includes(query) || tracker.includes(query);

    if (!matchesSearch) return false;

    if (!supportsIsActive.value || activeFilter.value === "all") return true;
    if (activeFilter.value === "active") return child.is_active === true;
    return child.is_active === false;
  });
});

const activeCount = computed(
  () => store.children.filter((child) => child.is_active === true).length,
);

function childStatusLabel(child: AdminChild): string {
  if (typeof child.is_active !== "boolean") return "Unbekannt";
  return child.is_active ? "Aktiv" : "Inaktiv";
}

function childStatusClass(child: AdminChild): string {
  if (typeof child.is_active !== "boolean") return "neutral";
  return child.is_active ? "active" : "inactive";
}

async function remove(child: AdminChild) {
  if (!child.id) return;
  await store.deleteChild(child.id);
}

onMounted(() => {
  store.loadChildren();
});
</script>

<template>
  <section class="admin-view">
    <header class="view-header">
      <div>
        <h2>Kinder</h2>
        <p class="muted">Suche nach Name oder Tracker UID.</p>
      </div>
      <div v-if="supportsIsActive" class="meta-chip">Aktiv: {{ activeCount }}</div>
    </header>

    <p v-if="store.error" class="error">{{ store.error }}</p>

    <div class="toolbar">
      <input
        v-model="searchTerm"
        type="search"
        class="input"
        placeholder="Kind suchen…"
        aria-label="Kind suchen"
      />

      <select
        v-if="supportsIsActive"
        v-model="activeFilter"
        class="select"
        aria-label="Nach Status filtern"
      >
        <option value="all">Alle Status</option>
        <option value="active">Nur aktiv</option>
        <option value="inactive">Nur inaktiv</option>
      </select>
    </div>

    <div v-if="store.loading" class="empty-state">Lade Kinder…</div>

    <div v-else-if="!filteredChildren.length" class="empty-state">
      Keine Kinder gefunden. Passe die Suche oder Filter an.
    </div>

    <ul v-else class="card-list">
      <li v-for="child in filteredChildren" :key="child.id" class="card">
        <div class="card-main">
          <p class="title">{{ child.name }}</p>
          <p class="meta">Tracker: {{ child.tracker_uid || "—" }}</p>
        </div>

        <span class="badge" :class="childStatusClass(child)">
          {{ childStatusLabel(child) }}
        </span>

        <button class="delete-btn" @click="remove(child)">Löschen</button>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.admin-view { display: grid; gap: 14px; }
.view-header { display: flex; justify-content: space-between; align-items: start; gap: 12px; }
.muted { margin: 4px 0 0; color: #64748b; font-size: 0.92rem; }
.meta-chip { padding: 6px 10px; border-radius: 999px; background: #eef2ff; color: #3730a3; font-weight: 600; font-size: 0.85rem; }
.toolbar { display: grid; gap: 10px; grid-template-columns: minmax(180px, 1fr) auto; }
.input, .select { border: 1px solid #dbe2ea; border-radius: 10px; padding: 9px 11px; background: #fff; }
.card-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
.card { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 10px; border: 1px solid #e6edf3; border-radius: 12px; padding: 12px; background: #fff; }
.title { margin: 0; font-weight: 600; }
.meta { margin: 3px 0 0; color: #64748b; font-size: 0.9rem; }
.badge { border-radius: 999px; padding: 5px 9px; font-size: 0.78rem; font-weight: 600; }
.badge.active { background: #dcfce7; color: #166534; }
.badge.inactive { background: #fee2e2; color: #991b1b; }
.badge.neutral { background: #f1f5f9; color: #334155; }
.delete-btn { border: 1px solid #ef4444; color: #b91c1c; background: #fff; border-radius: 9px; padding: 7px 10px; cursor: pointer; }
.delete-btn:hover { background: #fef2f2; }
.empty-state { border: 1px dashed #dbe2ea; border-radius: 12px; padding: 16px; color: #64748b; text-align: center; }
.error { color: #b91c1c; margin: 0; }
@media (max-width: 700px) {
  .toolbar { grid-template-columns: 1fr; }
  .card { grid-template-columns: 1fr; align-items: start; }
}
</style>
