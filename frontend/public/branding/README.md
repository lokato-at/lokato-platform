# Branding & Customization

Dieser Ordner enthält **alle anpassbaren Assets pro Einrichtung**. Du musst
nichts neu builden, wenn du hier etwas änderst — die Dateien werden direkt
vom Webserver (nginx bzw. Vite-Dev-Server) ausgeliefert.

> **Wichtig:** Der Inhalt dieses Ordners (außer dieses README, die `.example`-Dateien
> und die `.gitkeep`-Marker) ist über `.gitignore` vom Repo ausgeschlossen.
> Das ist Absicht: Logo, Einrichtungsname und Kinderfotos sind pro Installation
> individuell und sollen nicht in den Quellcode wandern.

## Erststart

```bash
cp frontend/public/branding/config.example.json \
   frontend/public/branding/config.json
# Dann config.json öffnen und facilityName etc. eintragen
```

## Was du anpassen kannst

### `config.json` — Einrichtungs-Konfiguration

Aus `config.example.json` kopieren und anpassen:

```json
{
  "facilityName": "Hort Pregarten",
  "facilityShortName": "HP",
  "tagline": "Interaktives Raumdisplay",
  "primaryColor": "#2A7CD9"
}
```

| Feld | Bedeutung |
|---|---|
| `facilityName` | Voller Name der Einrichtung. Erscheint als Untertitel im Header. Leer lassen für „nur Lokato". |
| `facilityShortName` | Kürzel für enge Layouts (Mobile, Tablet-Header). Optional. |
| `tagline` | Untertitel-Slogan (z. B. „Interaktives Raumdisplay"). Optional. |
| `primaryColor` | Akzentfarbe für aktive Nav-Buttons + Auth-Links (Hex). Default: Lokato-Blau `#2A7CD9`. |
| `primaryColorText` | Textfarbe für Buttons mit `primaryColor`-Hintergrund. `"white"` oder `"black"` — manuell so wählen dass es zur primaryColor passt. Default `"white"`. |
| `animations.files` | Array mit Dateinamen aus `animations/`, die als Welcome-Video getriggert werden. Leer = aus. |
| `animations.cooldownSeconds` | Mindestabstand zwischen zwei Welcome-Animationen. Default 10. |
| `animations.playWithSound` | Wenn `false`, Animationen laufen dauerhaft stumm. Default `true` (Ton ab erstem Tap). |

### `dashboard/`

Bilder für das Dashboard (z. B. Hintergründe, Logos die ins Dashboard
eingeblendet werden). Werden vom Frontend per Pfad `/branding/dashboard/<filename>`
referenziert.

### `children/`

Optional: Fallback-Fotos pro Kind fuer den Fall, dass kein Foto per Admin-UI
hochgeladen wurde.

**Empfohlener Weg seit Foto-Upload:** Foto im Admin-Bereich pro Kind hochladen
— es landet automatisch in `backend/storage/app/public/children/<id>.<ext>` und
wird ueber nginx unter `/storage/children/...` ausgeliefert.

**Auswahl-Reihenfolge im Frontend** (siehe `src/components/ChildPhoto.vue`):

1. `child.photo_url` aus der DB (vom Admin-Upload gesetzt, `/storage/...`)
2. `/branding/children/<id>.jpg` (manuell hier abgelegt, wenn 1 leer oder 404)
3. Initial-Buchstabe als Platzhalter

Du musst kein Foto setzen — fehlt eines, wird automatisch der Buchstabe gezeigt.

### `animations/`

Kurze Videos (z. B. `.mp4`, `.webm`), die in der Raum-Tablet-Ansicht
abgespielt werden, sobald ein **neues Kind im Raum** auftaucht. Die
Dateinamen müssen in `config.json` unter `animations.files` aufgelistet
sein — der Player picked eine davon zufällig aus.

**Triggerlogik in der Tablet-View**
- Wird ein neues Kind über das SSE-Update sichtbar → eine Animation startet
- Mehrere Kinder gleichzeitig (Gruppenscan) → nur **eine** Animation
- **Cooldown** (`animations.cooldownSeconds`, Default 10 s): in dieser
  Zeitspanne wird keine weitere Animation getriggert
- **Ton** (`animations.playWithSound`, Default `true`): wegen Browser-
  Autoplay-Policy ist die allererste Animation **stumm** und zeigt
  „Tippen für Ton" — nach dem ersten Tap aufs Tablet spielen alle
  folgenden Animationen mit Ton
- Tap aufs laufende Video bricht es ab

Referenz im Frontend: `/branding/animations/<filename>`. Dateinamen mit
Leerzeichen werden korrekt URL-encoded.

### `facility-logo.png` *(optional)*

Wenn diese Datei vorhanden ist, erscheint sie als Logo **links vom
„Lokato"-Schriftzug** im App-Header. Empfohlene Höhe: 44 px (das CSS
skaliert die Breite proportional). Auch `.svg`/`.webp` funktioniert,
solange der Dateiname `facility-logo.<ext>` ist — passe dann den
Pfad in `App.vue` an. Wenn die Datei fehlt: kein Logo angezeigt
(kein Fehler).

### `facility-banner.webp` *(optional)*

Wenn diese Datei vorhanden ist, erscheint sie als **full-width
Hero-Banner direkt unter dem Header** (analog zum früheren
Einrichtungs-Banner). Empfohlene Höhe 281 px, Bild wird mit
`object-fit: cover` auf die volle Browserbreite skaliert.
Fehlt die Datei: kein Banner.

## Wie das im Code funktioniert

Im Frontend gibt es ein Composable `useBranding()` (siehe
[`frontend/src/composables/useBranding.ts`](../../src/composables/useBranding.ts)),
das `config.json` einmalig beim App-Start lädt. App.vue verwendet es
für den Header-Untertitel und den Browser-Tab-Title.

## Was du **nicht** ändern solltest

- Den Ordnernamen `branding/` — der ist im Code referenziert
- Den Dateinamen `config.json` — fix
- Felder in `config.json` umbenennen — das Composable liest die exakten
  Namen oben aus
