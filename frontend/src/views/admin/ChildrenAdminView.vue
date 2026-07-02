<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, reactive, ref } from "vue";
import { useAdminDataStore } from "@/stores/adminDataStore";
import type { AdminChild } from "@/stores/adminDataStore";
import { useToast } from "@/composables/useToast";
import ConfirmDialog from "@/components/ConfirmDialog.vue";
import ChildPhoto from "@/components/ChildPhoto.vue";

type ActiveFilter = "all" | "active" | "inactive";

const store = useAdminDataStore();
const { success, error: toastError } = useToast();
const searchTerm = ref("");
const activeFilter = ref<ActiveFilter>("all");
const editingId = ref<number | null>(null);

const formCardRef = ref<HTMLElement | null>(null);
const nameInputRef = ref<HTMLInputElement | null>(null);
const fileInputRef = ref<HTMLInputElement | null>(null);

const pendingDelete = ref<AdminChild | null>(null);
const deleteBusy = ref(false);

const createForm = reactive({
  name: "",
  tracker_uid: "",
  is_active: false,
});

const editForm = reactive({
  name: "",
  tracker_uid: "",
  is_active: true,
});

// Foto-Upload braucht die child.id, bei create kennen wir die erst nach POST —
// deshalb erst speichern, dann Foto separat hochladen.
const pickedPhotoFile = ref<File | null>(null);
const pickedPhotoPreviewUrl = ref<string | null>(null);

const editingChild = computed<AdminChild | null>(() => {
  if (editingId.value === null) return null;
  return store.children.find((c) => c.id === editingId.value) ?? null;
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

function clearPickedPhoto() {
  if (pickedPhotoPreviewUrl.value) {
    URL.revokeObjectURL(pickedPhotoPreviewUrl.value);
  }
  pickedPhotoFile.value = null;
  pickedPhotoPreviewUrl.value = null;
  if (fileInputRef.value) fileInputRef.value.value = "";
}

function onPhotoPicked(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0] ?? null;
  if (!file) {
    clearPickedPhoto();
    return;
  }
  if (file.size > 5 * 1024 * 1024) {
    toastError("Foto ist zu groß. Maximal 5 MB.");
    input.value = "";
    return;
  }
  if (pickedPhotoPreviewUrl.value) {
    URL.revokeObjectURL(pickedPhotoPreviewUrl.value);
  }
  pickedPhotoFile.value = file;
  pickedPhotoPreviewUrl.value = URL.createObjectURL(file);
}

async function openEdit(child: AdminChild) {
  editingId.value = child.id;
  editForm.name = child.name ?? "";
  editForm.tracker_uid = child.tracker_uid ?? "";
  editForm.is_active = child.is_active ?? true;
  clearPickedPhoto();

  await nextTick();
  formCardRef.value?.scrollIntoView({ behavior: "smooth", block: "start" });
  nameInputRef.value?.focus();
}

function closeEdit() {
  editingId.value = null;
  editForm.name = "";
  editForm.tracker_uid = "";
  editForm.is_active = true;
  clearPickedPhoto();
}

function resetCreateForm() {
  createForm.name = "";
  createForm.tracker_uid = "";
  createForm.is_active = false;
  clearPickedPhoto();
}

async function createChild() {
  if (!createForm.name.trim()) return;
  store.clearError();

  const created = await store.createChild({
    name: createForm.name.trim(),
    tracker_uid: createForm.tracker_uid.trim() || null,
    photo_url: null,
    is_active: createForm.is_active,
  });

  if (store.error || !created) {
    toastError(store.error ?? "Fehler beim Erstellen");
    return;
  }

  if (pickedPhotoFile.value && created.id) {
    try {
      await store.uploadChildPhoto(created.id, pickedPhotoFile.value);
    } catch {
      toastError("Kind erstellt, aber Foto-Upload schlug fehl.");
      resetCreateForm();
      return;
    }
  }

  success(`"${created.name}" wurde erstellt`);
  resetCreateForm();
}

async function updateChild() {
  if (!editingId.value || !editForm.name.trim()) return;
  store.clearError();

  await store.updateChild(editingId.value, {
    name: editForm.name.trim(),
    tracker_uid: editForm.tracker_uid.trim() || null,
    is_active: editForm.is_active,
  });

  if (store.error) {
    toastError(store.error);
    return;
  }

  if (pickedPhotoFile.value) {
    try {
      await store.uploadChildPhoto(editingId.value, pickedPhotoFile.value);
    } catch {
      toastError("Daten gespeichert, aber Foto-Upload schlug fehl.");
      return;
    }
  }

  success(`"${editForm.name.trim()}" wurde gespeichert`);
  closeEdit();
}

async function removePhotoFromEditingChild() {
  if (!editingId.value) return;
  try {
    await store.deleteChildPhoto(editingId.value);
    success("Foto entfernt");
  } catch {
    toastError(store.error ?? "Foto konnte nicht entfernt werden.");
  }
}

function requestDelete(child: AdminChild) {
  if (!child.id) return;
  pendingDelete.value = child;
}

async function confirmDelete() {
  if (!pendingDelete.value || deleteBusy.value) return;
  const child = pendingDelete.value;

  deleteBusy.value = true;
  store.clearError();
  await store.deleteChild(child.id);

  if (store.error) {
    toastError(store.error);
  } else {
    success(`"${child.name}" wurde gelöscht`);
    if (editingId.value === child.id) closeEdit();
    pendingDelete.value = null;
  }
  deleteBusy.value = false;
}

// ----- Anlern-Modus: eingehende, noch keinem Kind zugewiesene Tracker-UIDs -----
const learnMode = ref(false);
const LEARN_POLL_MS = 1500;
let learnTimer: ReturnType<typeof setInterval> | null = null;
// nowTick treibt die "vor X s"-Anzeige an (reaktive Zeitbasis).
const nowTick = ref(Date.now());

function startLearnPolling() {
  void store.loadTrackerSightings();
  learnTimer = setInterval(() => {
    nowTick.value = Date.now();
    void store.loadTrackerSightings();
  }, LEARN_POLL_MS);
}

function stopLearnPolling() {
  if (learnTimer) {
    clearInterval(learnTimer);
    learnTimer = null;
  }
}

function toggleLearnMode() {
  learnMode.value = !learnMode.value;
  if (learnMode.value) startLearnPolling();
  else stopLearnPolling();
}

function formatSeen(iso?: string | null): string {
  if (!iso) return "";
  const secs = Math.max(0, Math.round((nowTick.value - new Date(iso).getTime()) / 1000));
  if (secs < 60) return `vor ${secs} s`;
  const mins = Math.floor(secs / 60);
  if (mins < 60) return `vor ${mins} min`;
  return `vor ${Math.floor(mins / 60)} h`;
}

async function applySighting(uid: string) {
  // In den gerade offenen Formular-Modus übernehmen (Bearbeiten oder Neu).
  if (editingId.value) editForm.tracker_uid = uid;
  else createForm.tracker_uid = uid;
  await nextTick();
  formCardRef.value?.scrollIntoView({ behavior: "smooth", block: "start" });
  nameInputRef.value?.focus();
}

function dismissSighting(uid: string) {
  void store.dismissTrackerSighting(uid);
}

onMounted(() => {
  store.loadChildren();
  store.connectSSE();
});

onUnmounted(() => {
  stopLearnPolling();
  store.disconnectSSE();
  if (pickedPhotoPreviewUrl.value) {
    URL.revokeObjectURL(pickedPhotoPreviewUrl.value);
  }
});
</script>

<template>
  <section class="admin-view">
    <header class="view-header">
      <div>
        <h2>Kinder</h2>
        <p class="muted">Suche, Statusfilter und schnelles Erstellen/Bearbeiten.</p>
      </div>
      <button
        type="button"
        class="learn-toggle"
        :class="{ active: learnMode }"
        @click="toggleLearnMode"
      >
        {{ learnMode ? "● Anlern-Modus beenden" : "Anlern-Modus starten" }}
      </button>
    </header>

    <section v-if="learnMode" class="learn-panel">
      <div class="learn-head">
        <h3>Eingehende Tracker</h3>
        <span class="learn-hint">
          Halte einen neuen Tracker an einen Scanner — er läuft hier live auf. Angezeigt werden
          nur UIDs, die noch keinem Kind gehören.
        </span>
      </div>

      <p v-if="!store.trackerSightings.length" class="learn-empty">
        Warte auf Scans…
      </p>

      <ul v-else class="learn-list">
        <li v-for="s in store.trackerSightings" :key="s.tracker_uid" class="learn-item">
          <div class="learn-main">
            <code class="learn-uid">{{ s.tracker_uid }}</code>
            <span class="learn-meta">
              {{ s.room_name || s.device_name || "Unbekanntes Gerät" }} · {{ formatSeen(s.last_seen_at) }}
            </span>
          </div>
          <div class="learn-actions">
            <button type="button" class="primary-btn small-btn" @click="applySighting(s.tracker_uid)">
              Übernehmen
            </button>
            <button type="button" class="secondary-btn small-btn" @click="dismissSighting(s.tracker_uid)">
              Verwerfen
            </button>
          </div>
        </li>
      </ul>
    </section>

    <p v-if="store.error" class="error">{{ store.error }}</p>

    <section ref="formCardRef" class="form-card">
      <h3>{{ editingId ? "Kind bearbeiten" : "Neues Kind" }}</h3>
      <form class="form-grid" @submit.prevent="editingId ? updateChild() : createChild()">
        <template v-if="editingId">
          <input
            ref="nameInputRef"
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
          <label class="checkbox">
            <input v-model="editForm.is_active" type="checkbox" />
            Aktiv
          </label>
        </template>
        <template v-else>
          <input
            ref="nameInputRef"
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
            Sofort aktiv (sonst erst beim ersten Scan)
          </label>
        </template>

        <!-- Foto-Upload-Bereich (gilt für create + edit) -->
        <div class="photo-row">
          <div class="photo-preview">
            <img
              v-if="pickedPhotoPreviewUrl"
              :src="pickedPhotoPreviewUrl"
              alt="Vorschau"
              class="photo-thumb"
            />
            <ChildPhoto
              v-else-if="editingChild"
              :child="editingChild"
              size="80px"
            />
            <div v-else class="photo-placeholder">Kein Foto</div>
          </div>
          <div class="photo-controls">
            <input
              ref="fileInputRef"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              class="file-input"
              @change="onPhotoPicked"
            />
            <p class="muted small">JPG, PNG oder WebP, max 5 MB</p>
            <button
              v-if="pickedPhotoFile"
              type="button"
              class="secondary-btn small-btn"
              @click="clearPickedPhoto"
            >
              Auswahl verwerfen
            </button>
            <button
              v-if="editingId && editingChild?.photo_url && !pickedPhotoFile"
              type="button"
              class="secondary-btn small-btn"
              @click="removePhotoFromEditingChild"
            >
              Foto entfernen
            </button>
          </div>
        </div>

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

    <div v-if="supportsIsActive" class="meta-chip">Aktiv: {{ activeCount }}</div>

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
        <ChildPhoto :child="child" size="48px" />
        <div class="card-main">
          <p class="title">{{ child.name }}</p>
          <p class="meta">ID: {{ child.id }} · Tracker: {{ child.tracker_uid || "—" }}</p>
        </div>

        <span class="badge" :class="childStatusClass(child)">
          {{ childStatusLabel(child) }}
        </span>

        <div class="actions">
          <button class="edit-btn" @click="openEdit(child)">Bearbeiten</button>
          <button class="delete-btn" @click="requestDelete(child)">Löschen</button>
        </div>
      </li>
    </ul>

    <ConfirmDialog
      :model-value="pendingDelete !== null"
      title="Kind löschen?"
      :message="pendingDelete
        ? `&quot;${pendingDelete.name}&quot; wird gelöscht — inklusive der kompletten Bewegungs-History (Movement-Log). Diese Aktion kann nicht rückgängig gemacht werden.`
        : ''"
      confirm-label="Löschen"
      variant="danger"
      :busy="deleteBusy"
      @update:model-value="(v) => { if (!v) pendingDelete = null }"
      @confirm="confirmDelete"
      @cancel="() => (pendingDelete = null)"
    />
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
  gap: 14px;
  border: 1px solid #e6edf3;
  border-radius: 12px;
  padding: 12px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}
.card-main { min-width: 0; }
.title { margin: 0; font-weight: 600; }
.meta { margin: 2px 0 0; font-size: 0.85rem; color: #64748b; }

/* Photo-Upload-Block im Form */
.photo-row {
  display: flex;
  gap: 16px;
  align-items: center;
  grid-column: 1 / -1;
  padding: 12px;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  border-radius: 10px;
}
.photo-preview {
  flex-shrink: 0;
  width: 80px;
  height: 80px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.photo-thumb {
  width: 80px;
  height: 80px;
  object-fit: cover;
  border-radius: 999px;
  border: 2px solid #2A7CD9;
}
.photo-placeholder {
  width: 80px;
  height: 80px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  background: #e2e8f0;
  color: #64748b;
  font-size: 0.8rem;
  text-align: center;
}
.photo-controls {
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex: 1;
  min-width: 0;
}
.file-input {
  font-family: inherit;
  font-size: 0.9rem;
}
.small { font-size: 0.8rem; }
.small-btn { font-size: 0.85rem; padding: 4px 10px; }

@media (max-width: 820px) {
  .form-grid, .toolbar { grid-template-columns: 1fr; }
  .card { grid-template-columns: auto 1fr; align-items: start; }
  .card .badge, .card .actions { grid-column: 1 / -1; }
  .photo-row { flex-direction: column; align-items: start; }
}

.view-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 0.75rem;
}

/* ----- Anlern-Modus ----- */
.learn-toggle {
  font-family: inherit;
  font-weight: 600;
  font-size: 0.9rem;
  padding: 8px 16px;
  border-radius: 999px;
  border: 1px solid #2a7cd9;
  background: #fff;
  color: #2a7cd9;
  cursor: pointer;
  transition: background 0.15s, color 0.15s, box-shadow 0.15s;
}
.learn-toggle:hover { background: #eff6ff; }
.learn-toggle.active {
  background: #2a7cd9;
  color: #fff;
  box-shadow: 0 0 0 4px rgba(42, 124, 217, 0.18);
}

.learn-panel {
  border: 1px solid #bfdbfe;
  background: #f0f7ff;
  border-radius: 12px;
  padding: 14px 16px;
  display: grid;
  gap: 10px;
}
.learn-head { display: grid; gap: 2px; }
.learn-head h3 { margin: 0; font-size: 1.05rem; }
.learn-hint { color: #475569; font-size: 0.85rem; }
.learn-empty { margin: 0; color: #64748b; font-style: italic; padding: 8px 0; }
.learn-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.learn-item {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 10px 12px;
}
.learn-main { display: grid; gap: 3px; min-width: 0; }
.learn-uid {
  font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
  font-size: 0.95rem;
  font-weight: 600;
  color: #0f172a;
  word-break: break-all;
}
.learn-meta { font-size: 0.8rem; color: #64748b; }
.learn-actions { display: flex; gap: 6px; flex-shrink: 0; }
</style>
