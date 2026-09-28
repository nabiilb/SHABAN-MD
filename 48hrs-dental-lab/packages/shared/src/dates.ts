/**
 * Calendar-day helpers. A "day" is a YYYY-MM-DD string in the lab's time zone:
 * the browser's zone in the web app, LAB_TIMEZONE on the server.
 */

export type DayOf = (v: string | number | Date) => string;

const pad = (n: number) => String(n).padStart(2, '0');

/** YYYY-MM-DD in the runtime's local time zone. */
export function localDay(v: string | number | Date) {
  const d = v instanceof Date ? v : new Date(v);
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

const dayFormatters = new Map<string, Intl.DateTimeFormat>();

/** YYYY-MM-DD in an IANA time zone (e.g. "Africa/Mogadishu"); local zone when omitted. */
export function dayIn(v: string | number | Date, timeZone?: string) {
  if (!timeZone) return localDay(v);
  let f = dayFormatters.get(timeZone);
  if (!f) {
    f = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' });
    dayFormatters.set(timeZone, f);
  }
  return f.format(v instanceof Date ? v : new Date(v));
}

/** A DayOf bound to one time zone. */
export function dayFnFor(timeZone?: string): DayOf {
  return (v) => dayIn(v, timeZone);
}

/** Calendar arithmetic on YYYY-MM-DD strings (time-zone free). */
export function shiftDay(day: string, days: number) {
  const [y, m, d] = day.split('-').map(Number);
  const t = new Date(Date.UTC(y, m - 1, d + days));
  return `${t.getUTCFullYear()}-${pad(t.getUTCMonth() + 1)}-${pad(t.getUTCDate())}`;
}

/** YYYY-MM for the month `months` before/after the month of `day`. */
export function shiftMonth(day: string, months: number) {
  const [y, m] = day.split('-').map(Number);
  const t = new Date(Date.UTC(y, m - 1 + months, 1));
  return `${t.getUTCFullYear()}-${pad(t.getUTCMonth() + 1)}`;
}

export const DAY_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

export function isDay(v: unknown): v is string {
  if (typeof v !== 'string' || !DAY_PATTERN.test(v)) return false;
  return shiftDay(v, 0) === v;
}

/** Offset of `timeZone` from UTC at `instant`, in milliseconds. */
function zoneOffsetMs(instant: number, timeZone: string) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).formatToParts(new Date(instant));
  const get = (t: Intl.DateTimeFormatPartTypes) => Number(parts.find((p) => p.type === t)?.value);
  const asUtc = Date.UTC(get('year'), get('month') - 1, get('day'), get('hour'), get('minute'), get('second'));
  return asUtc - Math.floor(instant / 1000) * 1000;
}

/** The instant a calendar day starts (00:00) in `timeZone`; local zone when omitted. */
export function startOfDay(day: string, timeZone?: string): Date {
  const [y, m, d] = day.split('-').map(Number);
  if (!timeZone) return new Date(y, m - 1, d);
  const guess = Date.UTC(y, m - 1, d);
  const first = guess - zoneOffsetMs(guess, timeZone);
  const second = guess - zoneOffsetMs(first, timeZone);
  return new Date(second);
}

/** The first instant of the following day — use as an exclusive upper bound. */
export function endOfDayExclusive(day: string, timeZone?: string): Date {
  return startOfDay(shiftDay(day, 1), timeZone);
}

export function daysAgo(n: number, from = new Date()) {
  const d = new Date(from);
  d.setDate(d.getDate() - n);
  return d;
}

/** Epoch milliseconds → ISO-8601 UTC string (the API's timestamp format). */
export function toIso(t: number) {
  return new Date(t).toISOString();
}
