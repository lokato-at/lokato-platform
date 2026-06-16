<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminChild } from "@/stores/adminDataStore";

type ActiveFilter = "all" | "active" | "inactive";

const store = useAdminDataStore();
const searchTerm = ref("");
const activeFilter = ref<ActiveFilter>("all");
const editingId = ref<number | null>(null);
const photoInputRef = ref<HTMLInputElement | null>(null);
const uploadingForId = ref<number | null>(null);
const uploadingInProgress = ref(false);

const createForm = reactive({
  name: "",
  tracker_uid: "",
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
  createForm.is_active = true;
}

async function createChild() {
  if (!createForm.name.trim()) return;

  await store.createChild({
    name: createForm.name.trim(),
    tracker_uid: createForm.tracker_uid.trim() || null,
    photo_url: null,
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

// Foto-Upload
function triggerPhotoUpload(child: AdminChild) {
  uploadingForId.value = child.id;
  photoInputRef.value?.click();
}

async function handlePhotoSelected(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file || !uploadingForId.value) return;

  uploadingInProgress.value = true;
  await store.uploadChildPhoto(uploadingForId.value, file);
  uploadingInProgress.value = false;
  uploadingForId.value = null;
  input.value = "";
}

onMounted(() => {
  store.loadChildren();
});
</script>

<template>
  <section class="admin-view">
    <!-- Versteckter File-Input für Foto-Upload -->
    <input
      ref="photoInputRef"
      type="file"
      accept="image/jpeg,image/png,image/jpg,image/webp"
      style="display: none"
      @change="handlePhotoSelected"
    />

    <header class="view-header">
      <div>
        <h2>Kinder</h2>
        <p class="muted">Suche, Statusfilter und schnelles Erstellen/Bearbeiten.</p>
      </div>
      <div v-if="supportsIsActive" class="meta-chip">Aktiv: {{ activeCount }}</div>
    </header>

    <p v-if="store.error" class="error">{{ store.error }}</p>
    <p v-if="uploadingInProgress" class="uploading-hint">Foto wird hochgeladen…</p>

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
            placeholder="Foto-URL (optional, oder per Klick auf Bild hochladen)"
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

        <!-- Foto-Avatar mit Upload-Klick -->
        <button
          type="button"
          class="avatar-btn"
          :disabled="uploadingInProgress"
          :title="`Foto für ${child.name} hochladen`"
          :aria-label="`Foto für ${child.name} hochladen`"
          @click="triggerPhotoUpload(child)"
        >
          <img
            v-if="child.photo_url"
            :src="child.photo_url"
            :alt="`Foto von ${child.name}`"
            class="avatar-img"
          />
          <span v-else class="avatar-placeholder">
            {{ child.name.charAt(0).toUpperCase() }}
          </span>
          <span class="avatar-overlay" aria-hidden="true">
            {{ uploadingInProgress && uploadingForId === child.id ? '…' : '📷' }}
          </span>
        </button>

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
@import '../styles/admin-shared.css';

.meta-chip { padding: 6px 10px; border-radius: 999px; background: #eef2ff; color: #3730a3; font-weight: 600; font-size: 0.85rem; }
.toolbar { grid-template-columns: minmax(180px, 1fr) auto; }
.card-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }

.card {
  display: grid;
  grid-template-columns: auto 1fr auto auto;
  align-items: center;
  gap: 12px;
  border: 1px solid #e6edf3;
  border-radius: 12px;
  padding: 12px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

/* Avatar */
.avatar-btn {
  position: relative;
  border: none;
  background: none;
  padding: 0;
  cursor: pointer;
  border-radius: 50%;
  flex-shrink: 0;
}
.avatar-btn:disabled { cursor: not-allowed; opacity: 0.6; }

.avatar-img,
.avatar-placeholder {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  object-fit: cover;
}

.avatar-placeholder {
  background: #e2e8f0;
  color: #334155;
  font-weight: 700;
  font-size: 1.1rem;
}

.avatar-overlay {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1rem;
  opacity: 0;
  transition: opacity 0.15s;
}
.avatar-btn:hover .avatar-overlay { opacity: 1; }

.uploading-hint {
  font-size: 0.9rem;
  color: #2563eb;
  margin: 0;
  padding: 6px 10px;
  background: #eff6ff;
  border-radius: 8px;
}

@media (max-width: 820px) {
  .form-grid, .toolbar { grid-template-columns: 1fr; }
  .card { grid-template-columns: auto 1fr; align-items: start; }
  .card .badge, .card .actions { grid-column: 2; }
}
</style>
