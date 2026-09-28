/** YYYY-MM-DD in the browser's local time zone (the lab's working day). */
export function localDay(v: string | number | Date) {
  const d = v instanceof Date ? v : new Date(v);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
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
