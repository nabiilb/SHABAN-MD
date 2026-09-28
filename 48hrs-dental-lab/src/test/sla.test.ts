import { describe, expect, it } from 'vitest';
import { computeDueAt, formatDuration, formatRemaining, getSlaInfo, HOUR_MS } from '@/lib/sla';

const received = new Date('2026-09-01T08:00:00Z');
const base = { status: 'in_production' as const, receivedAt: received.toISOString(), dueAt: null, deliveredAt: null };

describe('48-hour deadline', () => {
  it('computes due_at = received_at + 48 hours', () => {
    expect(computeDueAt(received).toISOString()).toBe('2026-09-03T08:00:00.000Z');
    expect(computeDueAt(received.toISOString(), 24).toISOString()).toBe('2026-09-02T08:00:00.000Z');
  });

  it('is on track with more than 12 hours left and reports remaining time', () => {
    const info = getSlaInfo(base, received.getTime() + 16.6 * HOUR_MS);
    expect(info.state).toBe('on_track');
    expect(formatRemaining(info)).toBe('31h 24m remaining');
    expect(info.progress).toBeCloseTo(16.6 / 48, 3);
  });

  it('flags at-risk (≤12h), critical (≤4h) and overdue from real timestamps', () => {
    expect(getSlaInfo(base, received.getTime() + 37 * HOUR_MS).state).toBe('at_risk');
    expect(getSlaInfo(base, received.getTime() + 45 * HOUR_MS).state).toBe('critical');
    const overdue = getSlaInfo(base, received.getTime() + 50 * HOUR_MS);
    expect(overdue.state).toBe('overdue');
    expect(formatRemaining(overdue)).toBe('Overdue by 2h 00m');
  });

  it('respects a stored due_at override', () => {
    const info = getSlaInfo({ ...base, dueAt: new Date(received.getTime() + 24 * HOUR_MS).toISOString() }, received.getTime() + 30 * HOUR_MS);
    expect(info.state).toBe('overdue');
  });

  it('is not started before the lab receives the case', () => {
    const info = getSlaInfo({ status: 'submitted', receivedAt: null, dueAt: null, deliveredAt: null });
    expect(info.state).toBe('not_started');
    expect(info.remainingMs).toBeNull();
  });

  it('freezes at delivery: met vs late', () => {
    const met = getSlaInfo({ ...base, status: 'delivered', deliveredAt: new Date(received.getTime() + 40 * HOUR_MS).toISOString() }, received.getTime() + 100 * HOUR_MS);
    expect(met.state).toBe('met');
    expect(met.turnaroundMs).toBe(40 * HOUR_MS);
    const late = getSlaInfo({ ...base, status: 'delivered', deliveredAt: new Date(received.getTime() + 49 * HOUR_MS).toISOString() });
    expect(late.state).toBe('late');
  });

  it('formats durations beyond 24h as hours', () => {
    expect(formatDuration(47 * HOUR_MS + 5 * 60_000)).toBe('47h 05m');
  });
});

describe('stageLabel', () => {
  it('shows the timeline stage while open and the status once closed', async () => {
    const { stageLabel } = await import('@/lib/workflow');
    expect(stageLabel('in_production')).toBe('In Production');
    expect(stageLabel('delivered')).toBe('Delivered');
    expect(stageLabel('completed')).toBe('Completed');
    expect(stageLabel('submitted')).toBe('Submitted');
  });
});
