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
