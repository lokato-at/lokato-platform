// Auswahl an Raum-Bildern für die Tablet-Ansicht (/tablet/<id>).
// Die Dateien liegen unter frontend/public/room-icons/ und werden statisch
// ausgeliefert. Neue Bilder einfach dort ablegen und hier eintragen — kein
// Upload, keine DB-Kopplung, kein Unique-Zwang. Gespeichert wird pro Raum nur
// der Dateiname (Spalte rooms.icon).

export interface RoomIconOption {
  file: string;
  label: string;
}

export const ROOM_ICONS_BASE = "/room-icons/";

export const ROOM_ICONS: RoomIconOption[] = [
  { file: "haus.svg", label: "Haus" },
  { file: "obergeschoss.svg", label: "Obergeschoss" },
  { file: "untergeschoss.svg", label: "Untergeschoss" },
  { file: "garten.svg", label: "Garten" },
  { file: "spielzimmer.svg", label: "Spielzimmer" },
  { file: "buch.svg", label: "Leseecke" },
  { file: "musik.svg", label: "Musik" },
  { file: "essen.svg", label: "Speiseraum" },
  { file: "stern.svg", label: "Stern" },
];

/**
 * Baut die URL zum Raum-Bild. Akzeptiert reinen Dateinamen ("haus.svg") ebenso
 * wie einen bereits vollständigen Pfad/URL (z.B. selbst abgelegtes Bild).
 */
export function roomIconUrl(icon?: string | null): string | null {
  if (!icon) return null;
  if (icon.startsWith("/") || icon.startsWith("http")) return icon;
  return ROOM_ICONS_BASE + icon;
}
