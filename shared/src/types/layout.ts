import type { ISODateString, UUID } from './common.js';

export type ZoneKind =
  | 'video'
  | 'image'
  | 'clock'
  | 'news'
  | 'rss'
  | 'html'
  | 'website'
  | 'youtube'
  | 'weather'
  | 'text';

/** Zone kinds that play a media playlist rather than a widget. */
export const MEDIA_ZONE_KINDS: ZoneKind[] = ['video', 'image'];

/** Zone rectangle expressed in percentages of the canvas (0-100). */
export interface Zone {
  id: UUID;
  kind: ZoneKind;
  x: number;
  y: number;
  width: number;
  height: number;
  /** Free-form per-widget configuration (RSS url, text, city, etc.). */
  config: Record<string, unknown>;
  /** Optional playlist bound to a media zone. */
  playlistId: UUID | null;
}

export type LayoutPreset = 'single' | 'two-zone' | 'three-zone' | 'four-zone' | 'custom';

export interface Layout {
  id: UUID;
  name: string;
  preset: LayoutPreset;
  zones: Zone[];
  createdAt: ISODateString;
  updatedAt: ISODateString;
}

export interface CreateLayoutInput {
  name: string;
  preset?: LayoutPreset;
  zones?: Zone[];
}

function zoneId(): UUID {
  // Browser and Node 19+ both expose crypto.randomUUID on the global object.
  return (globalThis as { crypto: { randomUUID(): string } }).crypto.randomUUID();
}

function zone(partial: Omit<Zone, 'id' | 'config' | 'playlistId'> & Partial<Pick<Zone, 'config' | 'playlistId'>>): Zone {
  return { id: zoneId(), config: {}, playlistId: null, ...partial };
}

/** Generates a sensible default set of zones for a preset. */
export function zonesForPreset(preset: LayoutPreset): Zone[] {
  switch (preset) {
    case 'single':
      return [zone({ kind: 'image', x: 0, y: 0, width: 100, height: 100 })];
    case 'two-zone':
      return [
        zone({ kind: 'video', x: 0, y: 0, width: 70, height: 100 }),
        zone({ kind: 'image', x: 70, y: 0, width: 30, height: 100 }),
      ];
    case 'three-zone':
      return [
        zone({ kind: 'video', x: 0, y: 0, width: 70, height: 80 }),
        zone({ kind: 'image', x: 70, y: 0, width: 30, height: 80 }),
        zone({ kind: 'text', x: 0, y: 80, width: 100, height: 20 }),
      ];
    case 'four-zone':
      return [
        zone({ kind: 'video', x: 0, y: 0, width: 50, height: 50 }),
        zone({ kind: 'image', x: 50, y: 0, width: 50, height: 50 }),
        zone({ kind: 'clock', x: 0, y: 50, width: 50, height: 50 }),
        zone({ kind: 'rss', x: 50, y: 50, width: 50, height: 50 }),
      ];
    default:
      return [];
  }
}
