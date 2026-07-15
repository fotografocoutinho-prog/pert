import type { ISODateString, UUID } from './common.js';

export type PlanName = 'free' | 'pro' | 'enterprise';

export interface Tenant {
  id: UUID;
  name: string;
  plan: PlanName;
  maxScreens: number | null;
  active: boolean;
  createdAt: ISODateString;
  updatedAt: ISODateString;
}

export interface License {
  plan: PlanName;
  maxScreens: number | null;
  usedScreens: number;
  remainingScreens: number | null;
}

/** Default screen limits per plan. */
export const PLAN_LIMITS: Record<PlanName, number | null> = {
  free: 5,
  pro: 50,
  enterprise: null,
};

export interface PlayEventInput {
  monitorId: UUID;
  contentId: UUID;
  durationSeconds: number;
}

export interface ContentPlayStat {
  contentId: UUID;
  contentName: string;
  plays: number;
  totalSeconds: number;
}

export interface PlayStats {
  from: ISODateString;
  to: ISODateString;
  totalPlays: number;
  byContent: ContentPlayStat[];
}
