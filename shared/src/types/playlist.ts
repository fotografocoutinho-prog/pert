import type { ISODateString, ScaleMode, UUID } from './common.js';

export interface PlaylistItem {
  id: UUID;
  contentId: UUID;
  position: number;
  /** Seconds an image/pdf page is shown. Videos always play to the end. */
  durationSeconds: number;
  scaleMode: ScaleMode;
  transition: TransitionType;
}

export type TransitionType = 'none' | 'fade' | 'slide' | 'zoom' | 'crossfade';

export interface Playlist {
  id: UUID;
  name: string;
  loop: boolean;
  shuffle: boolean;
  priority: number;
  startDate: ISODateString | null;
  endDate: ISODateString | null;
  active: boolean;
  items: PlaylistItem[];
  createdAt: ISODateString;
  updatedAt: ISODateString;
}

export interface CreatePlaylistInput {
  name: string;
  loop?: boolean;
  shuffle?: boolean;
  priority?: number;
  startDate?: ISODateString | null;
  endDate?: ISODateString | null;
}

export interface PlaylistItemInput {
  contentId: UUID;
  durationSeconds?: number;
  scaleMode?: ScaleMode;
  transition?: TransitionType;
}
