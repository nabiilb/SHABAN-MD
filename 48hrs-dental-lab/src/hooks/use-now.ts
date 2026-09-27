import { useSyncExternalStore } from 'react';

/**
 * Shared clock for live countdowns. One interval per tick size drives every
 * subscriber, so 50 timers on a page do not create 50 intervals.
 */
interface Clock {
  now: number;
  listeners: Set<() => void>;
  timer?: ReturnType<typeof setInterval>;
  subscribe: (listener: () => void) => () => void;
  getSnapshot: () => number;
}

const clocks = new Map<number, Clock>();

function clockFor(intervalMs: number): Clock {
  const existing = clocks.get(intervalMs);
  if (existing) return existing;
  const clock: Clock = {
    now: Date.now(),
    listeners: new Set(),
    subscribe(listener) {
      clock.listeners.add(listener);
      if (!clock.timer) {
        const tick = () => {
          clock.now = Date.now();
          clock.listeners.forEach((l) => l());
        };
        clock.timer = setInterval(tick, intervalMs);
        // The clock may have been idle: refresh once, asynchronously (never during subscribe).
        if (Date.now() - clock.now > 1000) setTimeout(tick, 0);
      }
      return () => {
        clock.listeners.delete(listener);
        if (!clock.listeners.size && clock.timer) {
          clearInterval(clock.timer);
          clock.timer = undefined;
        }
      };
    },
    getSnapshot: () => clock.now,
  };
  clocks.set(intervalMs, clock);
  return clock;
}

export function useNow(intervalMs = 30_000) {
  const clock = clockFor(intervalMs);
  return useSyncExternalStore(clock.subscribe, clock.getSnapshot, clock.getSnapshot);
}
