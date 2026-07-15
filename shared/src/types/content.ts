import type { ISODateString, UUID } from './common.js';

export type ContentKind = 'image' | 'video' | 'audio' | 'pdf';

export interface Content {
  id: UUID;
  name: string;
  kind: ContentKind;
  mimeType: string;
  sizeBytes: number;
  durationSeconds: number | null;
  width: number | null;
  height: number | null;
  storageKey: string;
  thumbnailKey: string | null;
  checksum: string;
  createdAt: ISODateString;
  updatedAt: ISODateString;
}

export const ACCEPTED_MIME_TYPES: Record<string, ContentKind> = {
  'image/png': 'image',
  'image/jpeg': 'image',
  'image/gif': 'image',
  'image/webp': 'image',
  'video/mp4': 'video',
  'video/webm': 'video',
  'video/quicktime': 'video',
  'audio/mpeg': 'audio',
  'audio/wav': 'audio',
  'application/pdf': 'pdf',
};
