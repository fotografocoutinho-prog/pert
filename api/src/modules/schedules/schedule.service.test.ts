import { describe, expect, it } from 'vitest';
import type { Schedule } from '@signage/shared';
import { selectActiveSchedule } from './schedule.service.js';

function make(partial: Partial<Schedule>): Schedule {
  return {
    id: partial.id ?? crypto.randomUUID(),
    name: 'test',
    monitorId: partial.monitorId ?? null,
    groupName: partial.groupName ?? null,
    playlistId: partial.playlistId ?? crypto.randomUUID(),
    priority: partial.priority ?? 0,
    startDate: partial.startDate ?? null,
    endDate: partial.endDate ?? null,
    startTime: partial.startTime ?? null,
    endTime: partial.endTime ?? null,
    weekdays: partial.weekdays ?? [],
    active: partial.active ?? true,
    createdAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
  };
}

// Wednesday 2026-01-14 at 12:30.
const wednesdayNoon = new Date('2026-01-14T12:30:00');

describe('selectActiveSchedule', () => {
  const monitorId = 'mon-1';

  it('returns null when nothing matches', () => {
    expect(selectActiveSchedule([], monitorId, null, wednesdayNoon)).toBeNull();
  });

  it('matches a monitor-targeted, all-day schedule', () => {
    const s = make({ monitorId, playlistId: 'p1' });
    expect(selectActiveSchedule([s], monitorId, null, wednesdayNoon)?.playlistId).toBe('p1');
  });

  it('honours the time window', () => {
    const inWindow = make({ monitorId, startTime: '12:00', endTime: '13:00' });
    const outWindow = make({ monitorId, startTime: '18:00', endTime: '19:00' });
    expect(selectActiveSchedule([inWindow], monitorId, null, wednesdayNoon)).not.toBeNull();
    expect(selectActiveSchedule([outWindow], monitorId, null, wednesdayNoon)).toBeNull();
  });

  it('honours weekday restrictions', () => {
    const wednesday = make({ monitorId, weekdays: [3] });
    const weekendOnly = make({ monitorId, weekdays: [0, 6] });
    expect(selectActiveSchedule([wednesday], monitorId, null, wednesdayNoon)).not.toBeNull();
    expect(selectActiveSchedule([weekendOnly], monitorId, null, wednesdayNoon)).toBeNull();
  });

  it('picks the highest priority among matches', () => {
    const low = make({ monitorId, priority: 1, playlistId: 'low' });
    const high = make({ monitorId, priority: 5, playlistId: 'high' });
    expect(selectActiveSchedule([low, high], monitorId, null, wednesdayNoon)?.playlistId).toBe('high');
  });

  it('matches by group when monitor is not directly targeted', () => {
    const group = make({ groupName: 'lobby', playlistId: 'grp' });
    expect(selectActiveSchedule([group], monitorId, 'lobby', wednesdayNoon)?.playlistId).toBe('grp');
    expect(selectActiveSchedule([group], monitorId, 'other', wednesdayNoon)).toBeNull();
  });

  it('ignores inactive schedules', () => {
    const inactive = make({ monitorId, active: false });
    expect(selectActiveSchedule([inactive], monitorId, null, wednesdayNoon)).toBeNull();
  });
});
