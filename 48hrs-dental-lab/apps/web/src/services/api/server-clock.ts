/**
 * The API is the source of truth for time: deadlines and session ends are
 * stamped with server time. Every API response carries X-Server-Time; the
 * offset from the device clock is applied to countdowns and session checks,
 * so a wrong device clock can neither fake an SLA state nor end a session
 * early. The offset is remembered, so it is known right after a reload.
 */
const KEY = '48hrs.clockOffset';

function stored() {
  try {
    const n = Number(localStorage.getItem(KEY));
    return Number.isFinite(n) ? n : 0;
  } catch {
    return 0;
  }
}

let offsetMs = stored();

export const serverClock = {
  now: () => Date.now() + offsetMs,
  offset: () => offsetMs,
  /** Called by the HTTP transport; uses the request's mid-point to cancel out network latency. */
  observe(serverTime: string | null, sentAt: number, receivedAt: number) {
    const server = Number(serverTime);
    if (!serverTime || !Number.isFinite(server)) return;
    offsetMs = Math.round(server - (sentAt + receivedAt) / 2);
    try {
      localStorage.setItem(KEY, String(offsetMs));
    } catch {
      /* storage unavailable — the offset still applies for this page */
    }
  },
};
