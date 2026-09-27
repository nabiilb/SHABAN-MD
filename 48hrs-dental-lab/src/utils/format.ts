import { format, formatDistanceToNowStrict, isToday, isYesterday } from 'date-fns';

let currencyCode = 'USD';

/** Called once settings load so every amount uses the lab's currency. */
export function setCurrency(code: string) {
  currencyCode = code || 'USD';
}

export function formatMoney(value: number | null | undefined, opts: { compact?: boolean } = {}) {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: currencyCode,
    notation: opts.compact ? 'compact' : 'standard',
    minimumFractionDigits: opts.compact ? 0 : Number.isInteger(value) ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(value);
}

export function formatNumber(value: number | null | undefined) {
  if (value === null || value === undefined) return '—';
  return new Intl.NumberFormat('en-US').format(value);
}

export function formatPercent(value: number | null | undefined, digits = 0) {
  if (value === null || value === undefined) return '—';
  return `${(value * 100).toFixed(digits)}%`;
}

function toDate(v: string | Date) {
  return typeof v === 'string' ? new Date(v) : v;
}

export function formatDate(v: string | Date | null | undefined) {
  if (!v) return '—';
  return format(toDate(v), 'dd MMM yyyy');
}

export function formatDateTime(v: string | Date | null | undefined) {
  if (!v) return '—';
  return format(toDate(v), 'dd MMM yyyy, HH:mm');
}

/** "Today 14:05", "Yesterday 09:12", "24 Sep 16:40" */
export function formatRelativeDay(v: string | Date | null | undefined) {
  if (!v) return '—';
  const d = toDate(v);
  if (isToday(d)) return `Today ${format(d, 'HH:mm')}`;
  if (isYesterday(d)) return `Yesterday ${format(d, 'HH:mm')}`;
  return format(d, 'dd MMM HH:mm');
}

export function formatTime(v: string | Date) {
  return format(toDate(v), 'HH:mm');
}

export function timeAgo(v: string | Date | null | undefined) {
  if (!v) return '—';
  return `${formatDistanceToNowStrict(toDate(v))} ago`;
}

export function formatBytes(bytes: number) {
  if (bytes >= 1_048_576) return `${(bytes / 1_048_576).toFixed(1)} MB`;
  if (bytes >= 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${bytes} B`;
}

export function initials(name: string) {
  return name
    .replace(/^Dr\.?\s+/i, '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase())
    .join('');
}

export function formatTeeth(teeth: number[]) {
  return teeth.length ? [...teeth].sort((a, b) => a - b).join(', ') : '—';
}
