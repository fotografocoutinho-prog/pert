export type UUID = string;
export type ISODateString = string;

export interface Paginated<T> {
  items: T[];
  total: number;
  page: number;
  pageSize: number;
}

export interface ApiError {
  error: string;
  message: string;
  details?: unknown;
}

export type Orientation = 'landscape' | 'portrait';

export type ScaleMode = 'fit' | 'fill' | 'stretch';

export type AspectRatio = '16:9' | '9:16' | '4:3' | '21:9' | '32:9' | 'custom';
