import type { ISODateString, UUID } from './common.js';

export type Weekday = 0 | 1 | 2 | 3 | 4 | 5 | 6;

export interface Schedule {
  id: UUID;
  name: string;
  monitorId: UUID | null;
  groupName: string | null;
  playlistId: UUID;
  priority: number;
  startDate: ISODateString | null;
  endDate: ISODateString | null;
  /** "HH:MM" 24h. */
  startTime: string | null;
  endTime: string | null;
  /** Empty array means every day. */
  weekdays: Weekday[];
  active: boolean;
  createdAt: ISODateString;
  updatedAt: ISODateString;
}
