<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminChild } from "@/stores/adminDataStore";

type ActiveFilter = "all" | "active" | "inactive";

const store = useAdminDataStore();
const searchTerm = ref("");
const activeFilter = ref<ActiveFilter>("all");
const editingId = ref<number | null>(null);

const createForm = reactive({
  name: "",
  tracker_uid: "",
  photo_url: "",
  is_active: true,
});

const editForm = reactive({
  name: "",
  tracker_uid: "",
  photo_url: "",
  is_active: true,
});

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

function openEdit(child: AdminChild) {
  editingId.value = child.id;
  editForm.name = child.name ?? "";
  editForm.tracker_uid = child.tracker_uid ?? "";
  editForm.photo_url = child.photo_url ?? "";
  editForm.is_active = child.is_active ?? true;
}

function closeEdit() {
  editingId.value = null;
  editForm.name = "";
  editForm.tracker_uid = "";
  editForm.photo_url = "";
  editForm.is_active = true;
}

function resetCreateForm() {
  createForm.name = "";
  createForm.tracker_uid = "";
  createForm.photo_url = "";
  createForm.is_active = true;
}

async function createChild() {
  if (!createForm.name.trim()) return;

  await store.createChild({
    name: createForm.name.trim(),
    tracker_uid: createForm.tracker_uid.trim() || null,
    photo_url: createForm.photo_url.trim() || null,
    is_active: createForm.is_active,
  });

  resetCreateForm();
}

async function updateChild() {
  if (!editingId.value || !editForm.name.trim()) return;

  await store.updateChild(editingId.value, {
    name: editForm.name.trim(),
    tracker_uid: editForm.tracker_uid.trim() || null,
    photo_url: editForm.photo_url.trim() || null,
    is_active: editForm.is_active,
  });

  closeEdit();
}

async function remove(child: AdminChild) {
  if (!child.id) return;
  await store.deleteChild(child.id);
  if (editingId.value === child.id) closeEdit();
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
        <p class="muted">Suche, Statusfilter und schnelles Erstellen/Bearbeiten.</p>
      </div>
      <div v-if="supportsIsActive" class="meta-chip">Aktiv: {{ activeCount }}</div>
    </header>

    <p v-if="store.error" class="error">{{ store.error }}</p>

    <section class="form-card">
      <h3>{{ editingId ? "Kind bearbeiten" : "Neues Kind" }}</h3>
      <form class="form-grid" @submit.prevent="editingId ? updateChild() : createChild()">
        <template v-if="editingId">
          <input
            v-model="editForm.name"
            type="text"
            class="input"
            placeholder="Name"
            required
          />
          <input
            v-model="editForm.tracker_uid"
            type="text"
            class="input"
            placeholder="Tracker UID (optional)"
          />
          <input
            v-model="editForm.photo_url"
            type="url"
            class="input"
            placeholder="Foto-URL (optional)"
          />
          <label class="checkbox">
            <input v-model="editForm.is_active" type="checkbox" />
            Aktiv
          </label>
        </template>
        <template v-else>
          <input
            v-model="createForm.name"
            type="text"
            class="input"
            placeholder="Name"
            required
          />
          <input
            v-model="createForm.tracker_uid"
            type="text"
            class="input"
            placeholder="Tracker UID (optional)"
          />
          <input
            v-model="createForm.photo_url"
            type="url"
            class="input"
            placeholder="Foto-URL (optional)"
          />
          <label class="checkbox" v-if="supportsIsActive">
            <input v-model="createForm.is_active" type="checkbox" />
            Aktiv
          </label>
        </template>

        <div class="form-actions">
          <button type="submit" class="primary-btn">
            {{ editingId ? "Speichern" : "Kind erstellen" }}
          </button>
          <button v-if="editingId" type="button" class="secondary-btn" @click="closeEdit">
            Abbrechen
          </button>
        </div>
      </form>
    </section>

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

        <div class="actions">
          <button class="edit-btn" @click="openEdit(child)">Bearbeiten</button>
          <button class="delete-btn" @click="remove(child)">Löschen</button>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.admin-view { display: grid; gap: 14px; }
.view-header { display: flex; justify-content: space-between; align-items: start; gap: 12px; }
.muted { margin: 4px 0 0; color: #64748b; font-size: 0.92rem; }
.meta-chip { padding: 6px 10px; border-radius: 999px; background: #eef2ff; color: #3730a3; font-weight: 600; font-size: 0.85rem; }
.form-card { border: 1px solid #e6edf3; border-radius: 12px; background: #fff; padding: 12px; }
.form-card h3 { margin: 0 0 10px; font-size: 1rem; }
.form-grid { display: grid; gap: 9px; grid-template-columns: repeat(2, minmax(180px, 1fr)); }
.form-actions { display: flex; gap: 8px; }
.toolbar { display: grid; gap: 10px; grid-template-columns: minmax(180px, 1fr) auto; }
.input, .select { border: 1px solid #dbe2ea; border-radius: 10px; padding: 9px 11px; background: #fff; }
.checkbox { display: inline-flex; align-items: center; gap: 8px; color: #334155; font-size: 0.9rem; }
.primary-btn, .secondary-btn, .edit-btn, .delete-btn { border-radius: 9px; padding: 7px 10px; cursor: pointer; background: #fff; }
.primary-btn { border: 1px solid #1d4ed8; color: #1d4ed8; }
.secondary-btn { border: 1px solid #cbd5e1; color: #334155; }
.edit-btn { border: 1px solid #c7d2fe; color: #3730a3; }
.delete-btn { border: 1px solid #ef4444; color: #b91c1c; }
.delete-btn:hover { background: #fef2f2; }
.card-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
.card { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 10px; border: 1px solid #e6edf3; border-radius: 12px; padding: 12px; background: #fff; }
.title { margin: 0; font-weight: 600; }
.meta { margin: 3px 0 0; color: #64748b; font-size: 0.9rem; }
.actions { display: inline-flex; gap: 8px; }
.badge { border-radius: 999px; padding: 5px 9px; font-size: 0.78rem; font-weight: 600; }
.badge.active { background: #dcfce7; color: #166534; }
.badge.inactive { background: #fee2e2; color: #991b1b; }
.badge.neutral { background: #f1f5f9; color: #334155; }
.empty-state { border: 1px dashed #dbe2ea; border-radius: 12px; padding: 16px; color: #64748b; text-align: center; }
.error { color: #b91c1c; margin: 0; }
@media (max-width: 820px) {
  .form-grid, .toolbar { grid-template-columns: 1fr; }
  .card { grid-template-columns: 1fr; align-items: start; }
}
</style>
