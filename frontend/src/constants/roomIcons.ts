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
  { file: "raumsymbol_01.svg", label: "Raumsymbol 1" },
  { file: "raumsymbol_02.svg", label: "Raumsymbol 2" },
  { file: "raumsymbol_03.svg", label: "Raumsymbol 3" },
  { file: "raumsymbol_04.svg", label: "Raumsymbol 4" },
  { file: "raumsymbol_05.svg", label: "Raumsymbol 5" },
  { file: "raumsymbol_06.svg", label: "Raumsymbol 6" },
  { file: "raumsymbol_07.svg", label: "Raumsymbol 7" },
  { file: "raumsymbol_08.svg", label: "Raumsymbol 8" },
];

/**
 * Baut die URL zum Raum-Bild. Akzeptiert reinen Dateinamen ("raumsymbol_01.svg") ebenso
 * wie einen bereits vollständigen Pfad/URL (z.B. selbst abgelegtes Bild).
 */
export function roomIconUrl(icon?: string | null): string | null {
  if (!icon) return null;
  if (icon.startsWith("/") || icon.startsWith("http")) return icon;
  return ROOM_ICONS_BASE + icon;
}
