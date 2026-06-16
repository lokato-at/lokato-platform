📘 Lokato Platform – Frontend (Source Code Dokumentation)


🎯 Rolle des Frontends

Das Frontend der Lokato Platform ist eine Single-Page-Application, die als reine Präsentations- und Interaktionsschicht fungiert.Es stellt Dashboards und Admin-Views bereit und kommuniziert ausschließlich über REST-APIs sowie Server-Sent Events (SSE) mit dem Backend.

Es existiert keine Geschäftslogik im Frontend.Alle Regeln, Validierungen und Entscheidungen liegen vollständig im Backend.

🧠 Gesamtarchitektur

Das Frontend ist klar in folgende Schichten unterteilt:

*   Views → Seiten & Benutzerinteraktion
    
*   Stores → Zentrale Daten- und Zustandsverwaltung
    
*   Router → Navigation & Seitenstruktur
    
*   API Layer → Kommunikation mit dem Backend
    

Diese Trennung sorgt für:

*   gute Wartbarkeit
    
*   klare Verantwortlichkeiten
    
*   einfache Erweiterbarkeit
    

🧭 Routing (src/router)

Die Navigation wird zentral über Vue Router definiert.

Struktur & Routen:

*   / → Redirect auf /dashboard
    
*   /dashboard → DashboardView
    
*   /admin/home → AdminView (Übersicht)
    
*   /admin/children → ChildrenAdminView
    
*   /admin/rooms → RoomsAdminView
    
*   /admin/devices → DevicesAdminView
    
*   /admin/movements → MovementAdminView

Tablet Route

*  #/tablet/roomId → TabletView 
    

Eigenschaften:

*   client-seitige Navigation ohne Page Reload
    
*   Fallback-Route leitet unbekannte URLs auf /dashboard
    

📁 Views (src/views)

DashboardViewÖffentliche Übersichtsseite zur Darstellung des aktuellen Systemzustands.

Zeigt:

*   alle Räume
    
*   aktuelle Belegung pro Raum
    
*   letzte Bewegungsereignisse
    

Reagiert live auf Backend-Events über Server-Sent Events (SSE).

AdminView (Admin-Home)Zentrale Einstiegsseite für den Administrationsbereich.

Zeigt aggregierte Informationen:

*   Anzahl der Kinder
    
*   Anzahl der Räume
    
*   Anzahl der Geräte
    

Dient als Einstiegspunkt zu allen Admin-Funktionen.

Admin-Views (src/views/admin)

*   ChildrenAdminViewCRUD-Verwaltung von Kindern
    
*   RoomsAdminViewVerwaltung von Räumen inklusive Metadaten
    
*   DevicesAdminViewVerwaltung von Scanner-Geräten und Raumzuordnung
    
*   MovementAdminViewSimulation von Scan- und Bewegungsereignissen
    

Alle Admin-Views greifen ausschließlich auf den AdminDataStore zu.

🗂 State-Management (Pinia Stores)

1️⃣ AdminDataStore

Zweck:Verwaltet sämtliche admin-relevanten Daten und Aktionen.

Enthält:

*   children
    
*   rooms
    
*   devices
    
*   Lade- und Fehlerstatus
    
*   letztes simuliertes Scan-Event
    

Unterstützt:

*   CRUD-Operationen für
    
    *   Kinder
        
    *   Räume
        
    *   Geräte
        
*   Simulation von Bewegungsereignissen über den Scan-Endpunkt
    
*   paralleles Laden aller Admin-Daten
    

Der AdminDataStore ist die zentrale Datenquelle für alle Admin-Views.

2️⃣ DashboardDataStore

Zweck:Versorgt das Dashboard mit aktuellen und live aktualisierten Daten.

Verantwortlichkeiten:

*   Laden aller Räume
    
*   Laden der aktuellen Belegung pro Raum
    
*   Laden der letzten Bewegungen
    
*   Verwaltung einer Server-Sent-Event-Verbindung
    

Besonderheit: Server-Sent Events (SSE)

Es gibt **einen einzigen** SSE-Endpoint im Backend (`GET /api/stream`). Modus und Filterung werden über Query-Params gesteuert:

| Aufrufer | URL | Verhalten |
|---|---|---|
| Dashboard | `/api/stream` | sieht Events aller Räume |
| Raumtablet | `/api/stream?room=3&initial=1` | nur Events des angegebenen Raums + initialer Occupancy-Snapshot |
| Reconnect | `…&last_event_id=movement:42;alert:5` | Cursor-basierter Resume nach `stream.draining` |

Der Store registriert sich auf folgende benannte Events:

*   `child.moved` — Bewegungs-Eintrag
*   `room.occupancy.updated` — neuer Snapshot für einen Raum
*   `room.alert.raised` — Alert (Kapazitäts-Überschreitung etc.)
*   `stream.ready` — initial nach Connect (Cursor-Position)
*   `stream.draining` — Server fordert Reconnect (Connection-Lifetime erreicht)

Im Backend pollt der SSE-Controller alle 500 ms, **aber** mit Cache-Gate (`App\Support\SseChangeSignal`): solange seit dem letzten Tick kein Scan eingegangen ist, werden die DB-Queries komplett übersprungen. Dadurch:

*   Idle-DB-Last = 0 (statt vorher 2 Queries/s pro Verbindung)
*   Scan→UI-Latenz < 500 ms
*   Automatische Aktualisierung ohne Browser-seitiges Polling

3️⃣ DevDataStore (nur Entwicklung)

Zweck:Reiner Entwicklungs- und Test-Store.

*   Lädt alle öffentlichen und Admin-Endpunkte
    
*   Testet Detail-Routen mit Beispiel-IDs
    
*   Prüft Pagination und Datenformate
    

Dieser Store ist nicht für den Produktivbetrieb gedacht.

🔌 Backend-Kommunikation

Die Kommunikation zwischen Frontend und Backend erfolgt ausschließlich über REST-Schnittstellen sowie Server-Sent Events.

*   Sämtliche API-Zugriffe sind zentral gekapselt
    
*   Views und UI-Elemente führen keine direkten HTTP-Requests aus
    
*   Alle Requests werden über die Stores ausgelöst
    

Vorteile:

*   einheitliche Fehlerbehandlung
    
*   klare Trennung zwischen UI und Datenzugriff
    
*   Austauschbarkeit der Backend-API
    
*   verbesserte Wartbarkeit
    

🧩 Komponenten- und UI-Logik

Das Frontend folgt einem klaren Rollenmodell:

*   Views enthalten Seiten- und Interaktionslogik
    
*   Stores enthalten Daten- und Kommunikationslogik
    
*   UI-Elemente dienen ausschließlich der Darstellung
    

UI-Elemente:

*   zeigen Daten aus den Stores an
    
*   lösen Aktionen aus (z. B. Klicks)
    
*   enthalten keine Geschäftslogik
    

🔄 Datenfluss innerhalb der Anwendung

Der Datenfluss ist in allen Bereichen einheitlich aufgebaut:

*   Benutzeraktion
    
*   View ruft eine Store-Action auf
    
*   Store kommuniziert mit REST-API oder empfängt SSE-Event
    
*   Store aktualisiert den zentralen State
    
*   UI reagiert automatisch auf die State-Änderung
    

Dieser reaktive Ablauf sorgt für konsistentes Verhalten und verhindert redundante Zustände.

🔐 Fehler- und Ladezustände

Alle relevanten Stores verwalten explizit:

*   Ladezustände während API-Requests
    
*   Fehlerzustände mit klaren Fehlermeldungen
    

Diese Zustände werden in den Views visualisiert, um transparentes Benutzerfeedback zu gewährleisten.

📈 Echtzeit-Fähigkeit

Das Dashboard nutzt Server-Sent Events zur Live-Aktualisierung.

*   automatische Aktualisierung der Raumbelegung
    
*   Anzeige neuer Bewegungsereignisse
    
*   kein Polling notwendig
    
*   geringe Netzwerklast
    

Dies macht das Frontend besonders geeignet für Monitoring- und Übersichtsanwendungen.

🧾 Zusammenfassung

*   klare Trennung von Routing, Views, Stores und Backend-Kommunikation
    
*   keine Geschäftslogik im Frontend
    
*   einheitlicher Datenfluss über zentrale Stores
    
*   saubere Trennung zwischen Admin- und Dashboard-Bereich
    
*   Echtzeit-Updates über Server-Sent Events
    
*   sehr gut erweiterbar für zukünftige Anforderungen
