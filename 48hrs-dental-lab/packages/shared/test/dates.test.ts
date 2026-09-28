import { describe, expect, it } from 'vitest';
import { dayIn, endOfDayExclusive, isDay, shiftDay, shiftMonth, startOfDay } from '../src/dates';

describe('lab-day helpers', () => {
  it('formats a day in any IANA zone', () => {
    const t = Date.UTC(2026, 8, 28, 22, 30); // 22:30 UTC = 01:30 next day in Mogadishu (UTC+3)
    expect(dayIn(t, 'UTC')).toBe('2026-09-28');
    expect(dayIn(t, 'Africa/Mogadishu')).toBe('2026-09-29');
  });

  it('finds the start and end of a day in a zone', () => {
    expect(startOfDay('2026-09-29', 'Africa/Mogadishu').toISOString()).toBe('2026-09-28T21:00:00.000Z');
    expect(endOfDayExclusive('2026-09-29', 'Africa/Mogadishu').toISOString()).toBe('2026-09-29T21:00:00.000Z');
    // Across a DST change (London springs forward on 2026-03-29).
    expect(startOfDay('2026-03-29', 'Europe/London').toISOString()).toBe('2026-03-29T00:00:00.000Z');
    expect(startOfDay('2026-03-30', 'Europe/London').toISOString()).toBe('2026-03-29T23:00:00.000Z');
  });

  it('shifts days and months and validates day strings', () => {
    expect(shiftDay('2026-02-28', 1)).toBe('2026-03-01');
    expect(shiftDay('2026-01-01', -1)).toBe('2025-12-31');
    expect(shiftMonth('2026-01-15', -5)).toBe('2025-08');
    expect(isDay('2026-02-29')).toBe(false);
    expect(isDay('2028-02-29')).toBe(true);
    expect(isDay('2026-9-1')).toBe(false);
  });
});
