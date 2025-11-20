<template>
  <div class="dev-view">
    <h1>📘 DEV – API Rohdaten & Dokumentation</h1>

    <p class="desc">
      Diese Seite dient als interne <strong>API-Dokumentation</strong> und zeigt alle Rohdaten,
      die dein <code>devDataStore</code> von der API lädt.
      Dies ist besonders hilfreich für Debugging, Testen, Datenstrukturen verstehen und
      zum schnellen Vergleichen mit Insomnia.
      <br /><br />
      <strong>Base URL:</strong> <code>http://localhost:8001/api/v1</code>
    </p>

    <div v-if="store.loading" class="info-box">⏳ Die API-Daten werden geladen…</div>
    <div v-if="store.error" class="error-box">❌ Fehler: {{ store.error }}</div>

    <!-- ================================================================= -->
    <!-- 1. PUBLIC API -->
    <!-- ================================================================= -->
    <h2>1. Public API – Öffentliche Daten</h2>

    <p>
      Dies sind die Endpunkte, die für die allgemeine Darstellung des Systems genutzt werden –
      z. B. aktuelle Kinderlisten, Raumübersichten oder Belegungsstatus.
      Alle Requests hier sind öffentlich zugänglich.
    </p>

    <!-- /children -->
    <section>
      <h3>1.1 GET /children</h3>
      <p class="endpoint-desc">
        Gibt eine Liste aller aktiven Kinder zurück.
        Jedes Kind enthält Name, Tracker-UID, Aktivitätsstatus und optional ein Foto.
      </p>
      <pre>{{ format(store.public.children) }}</pre>
    </section>

    <!-- /children/{id} -->
    <section>
      <h3>1.2 GET /children/{id}</h3>
      <p class="endpoint-desc">
        Liefert die Detaildaten eines spezifischen Kindes.
        In dieser Dev-Ansicht wird als Beispiel <strong>ID = 1</strong> geladen.
      </p>
      <pre>{{ format(store.public.child) }}</pre>
    </section>

    <!-- /rooms -->
    <section>
      <h3>1.3 GET /rooms</h3>
      <p class="endpoint-desc">
        Liste aller Räume – inklusive Kapazität, Toleranz, Aktivitätsstatus und
        aktueller Kinderanzahl.
        Zusätzlich enthält jeder Raum ein <strong>Status-Objekt</strong>, das angibt:
        <ul>
          <li><code>over_capacity</code> – ist der Raum überfüllt?</li>
          <li><code>within_tolerance</code> – leichte Überbelegung innerhalb der Toleranz?</li>
        </ul>
      </p>
      <pre>{{ format(store.public.rooms) }}</pre>
    </section>

    <!-- /rooms/{id}/occupancy -->
    <section>
      <h3>1.4 GET /rooms/{id}/occupancy</h3>
      <p class="endpoint-desc">
        Zeigt die Belegung eines bestimmten Raums inklusive der anwesenden Kinder.
        Beispiel: <strong>Raum mit ID = 1</strong>.
      </p>
      <pre>{{ format(store.public.roomOccupancy) }}</pre>
    </section>

    <!-- ================================================================= -->
    <!-- 2. MOVEMENT LOG -->
    <!-- ================================================================= -->
    <h2>2. Movement Log – Eintritts- & Austritts-Historie</h2>

    <p>
      Das Movement Log zeigt alle registrierten Bewegungen von Kindern zwischen Räumen.
      Jeder Eintrag enthält:
    </p>
    <ul>
      <li><code>child_id</code> – welches Kind?</li>
      <li><code>from_room_id</code> – aus welchem Raum?</li>
      <li><code>to_room_id</code> – in welchen Raum?</li>
      <li><code>event_time</code> – wann?</li>
    </ul>

    <section>
      <h3>2.1 GET /movement-log</h3>
      <p class="endpoint-desc">
        Paginiert alle Bewegungen aller Kinder.
        Die API liefert typische Laravel-Pagination inkl. <code>current_page</code>,
        <code>total</code>, <code>links</code> usw.
      </p>
      <pre>{{ format(store.movement.all) }}</pre>
    </section>

    <section>
      <h3>2.2 GET /children/{id}/movement-log</h3>
      <p class="endpoint-desc">
        Bewegungen eines einzelnen Kindes.
        In dieser Dev-Seite wird Beispiel <strong>ID = 1</strong> geladen.
      </p>
      <pre>{{ format(store.movement.byChild) }}</pre>
    </section>

    <!-- ================================================================= -->
    <!-- 3. ADMIN API -->
    <!-- ================================================================= -->
    <h2>3. Admin API – Verwaltungsdaten</h2>

    <p>
      Diese Endpunkte dienen der Administration innerhalb des Systems.
      Sie enthalten deutlich mehr Daten (z. B. Fotos, Tracker-Keys,
      Raumdetails, Geräteinfos).
    </p>

    <section>
      <h3>3.1 GET /admin/children</h3>
      <p class="endpoint-desc">
        Admin-Liste aller Kinder.
        (Mehr Details als in der Public-Liste.)
      </p>
      <pre>{{ format(store.admin.children) }}</pre>
    </section>

    <section>
      <h3>3.2 GET /admin/children/{id}</h3>
      <p class="endpoint-desc">
        Vollständige Admin-Daten für ein Kind (Beispiel ID = 1).
      </p>
      <pre>{{ format(store.admin.child) }}</pre>
    </section>

    <section>
      <h3>3.3 GET /admin/rooms</h3>
      <p class="endpoint-desc">
        Alle Räume mit administrativen Daten (Kapazität, Toleranz usw.).
      </p>
      <pre>{{ format(store.admin.rooms) }}</pre>
    </section>

    <section>
      <h3>3.4 GET /admin/rooms/{id}</h3>
      <p class="endpoint-desc">
        Raumdetail für Administratoren (Beispiel ID = 1).
      </p>
      <pre>{{ format(store.admin.room) }}</pre>
    </section>

    <section>
      <h3>3.5 GET /admin/devices</h3>
      <p class="endpoint-desc">
        Liste aller registrierten Geräte (z. B. Türscanner).
        Ein Gerät enthält u. a.:
      </p>
      <ul>
        <li><code>device_key</code> – eindeutiger Schlüssel</li>
        <li><code>room_id</code> – zu welchem Raum gehört das Gerät?</li>
        <li><code>last_seen</code> – letzter Kontakt</li>
      </ul>
      <pre>{{ format(store.admin.devices) }}</pre>
    </section>

    <section>
      <h3>3.6 GET /admin/devices/{id}</h3>
      <p class="endpoint-desc">
        Detail eines einzelnen Geräts (Beispiel ID = 1).
      </p>
      <pre>{{ format(store.admin.device) }}</pre>
    </section>
  </div>
</template>

<script setup lang="ts">
import { onMounted } from "vue";
import { useDevDataStore } from "../stores/devDataStore";

const store = useDevDataStore();

/**
 * Hilfsfunktion für pretty JSON Ausgabe
 */
const format = (data: unknown) => JSON.stringify(data, null, 2);

onMounted(() => {
  store.fetchAll();
});
</script>

<style scoped>
.dev-view {
  padding: 40px;
  max-width: 1100px;
  margin: auto;
  font-family: "Inter", system-ui, sans-serif;
  color: #e5e7eb;
  background: #0d0f12;
}

/* ---------- Headlines ---------- */
h1 {
  font-size: 42px;
  font-weight: 800;
  color: #ffffff;
  margin-bottom: 25px;
}

h2 {
  margin-top: 50px;
  margin-bottom: 15px;
  font-size: 28px;
  font-weight: 700;
  color: #c3ddff;
  border-left: 5px solid #3a8bfd;
  padding-left: 12px;
}

h3 {
  font-size: 20px;
  margin-bottom: 8px;
  color: #9ec4ff;
  font-weight: 600;
}

/* ---------- Text ---------- */
.desc {
  margin-bottom: 25px;
  color: #9ca3af;
  line-height: 1.7em;
  font-size: 15px;
}

.endpoint-desc {
  margin: 5px 0 10px;
  color: #b0b4bd;
  line-height: 1.4em;
}

/* ---------- Section Box ---------- */
section {
  background: #161a20;
  padding: 20px;
  border-radius: 12px;
  margin-bottom: 30px;
  border: 1px solid #1f242d;
  box-shadow: 0 0 20px rgba(0, 0, 0, 0.45);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

section:hover {
  transform: translateY(-2px);
  box-shadow: 0 0 30px rgba(0, 0, 0, 0.65);
}

/* ---------- JSON Output ---------- */
pre {
  background: #0b0d10;
  color: #54ff54;
  padding: 16px;
  border-radius: 10px;
  font-size: 14px;
  overflow-x: auto;
  max-height: 500px;
  border: 1px solid #262c36;
  box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.55);
  transition: 0.2s ease;
}

pre:hover {
  border-color: #3a8bfd;
}

/* ---------- Info / Error ---------- */
.info-box {
  background: #1a2d47;
  border-left: 5px solid #3a8bfd;
  padding: 14px 20px;
  color: #cfe2ff;
  border-radius: 6px;
  margin-bottom: 20px;
  font-weight: 500;
}

.error-box {
  background: #3d1515;
  border-left: 5px solid #ff4b4b;
  padding: 14px 20px;
  color: #ffd7d7;
  border-radius: 6px;
  margin-bottom: 20px;
  font-weight: 500;
}

/* ---------- Code inline ---------- */
code {
  background: #1a1f25;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 13px;
  color: #80c1ff;
}

</style>
