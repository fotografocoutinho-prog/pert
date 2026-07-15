import type { ISODateString, Orientation, ScaleMode, UUID } from './common.js';
import type { ContentKind } from './content.js';
import type { TransitionType } from './playlist.js';
import type { ZoneKind } from './layout.js';

/** A playlist item with its content resolved, ready to render. */
export interface ResolvedItem {
  contentId: UUID;
  kind: ContentKind;
  mimeType: string;
  durationSeconds: number;
  scaleMode: ScaleMode;
  transition: TransitionType;
}

export interface ResolvedPlaylist {
  id: UUID;
  loop: boolean;
  shuffle: boolean;
  items: ResolvedItem[];
}

export interface ResolvedZone {
  id: UUID;
  kind: ZoneKind;
  x: number;
  y: number;
  width: number;
  height: number;
  config: Record<string, unknown>;
  playlist: ResolvedPlaylist | null;
}

/**
 * Everything a player needs to render right now — the assigned layout with each
 * zone's playlist resolved, plus a full-screen fallback playlist (used when no
 * layout is set, and driven by the active schedule when one matches).
 */
export interface PlayerState {
  monitorId: UUID;
  orientation: Orientation;
  resolution: string | null;
  layout: { id: UUID; zones: ResolvedZone[] } | null;
  fallbackPlaylist: ResolvedPlaylist | null;
  activeScheduleId: UUID | null;
  generatedAt: ISODateString;
}
