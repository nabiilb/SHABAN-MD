import { dayFnFor, endOfDayExclusive, startOfDay } from '@48hrs/shared/dates';
import { env } from '../config/env.ts';

/** Calendar day (YYYY-MM-DD) in the lab's time zone — "today", report days, dashboard periods. */
export const labDay = dayFnFor(env.LAB_TIMEZONE);

/** First instant of a lab day. */
export const labDayStart = (day: string) => startOfDay(day, env.LAB_TIMEZONE);

/** First instant after a lab day (exclusive upper bound). */
export const labDayEnd = (day: string) => endOfDayExclusive(day, env.LAB_TIMEZONE);

export const iso = (d: Date | null | undefined) => (d ? d.toISOString() : null);

/** Current year in the lab's zone, for case and invoice numbers. */
export const labYear = (now = Date.now()) => Number(labDay(now).slice(0, 4));
