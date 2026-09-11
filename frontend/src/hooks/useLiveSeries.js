// Turns a polled sensor series into one that APPENDS, and a chart window that SCROLLS.
//
// The trend endpoint answers every poll with a fixed window - the newest N readings - so the
// naive thing, mapping server points straight into the chart, hands Recharts a brand new array
// of brand new objects every five seconds. Even with animation switched off the whole path is
// rebuilt and the category axis re-flows, and that is what reads as a flash from the back of a
// room. Nothing is actually wrong with the data; the chart is just being told to start over.
//
// So we keep every reading we have seen, keyed by its stable `id` (the data contract in
// COORDINATION.md pins that id down precisely because these charts depend on it), and hand back
// a new array ONLY when a genuinely new reading arrives. A poll that brings nothing new returns
// the identical reference, React bails out, and the chart does not re-render at all.
import { useEffect, useRef, useState } from "react";

// Readings kept per sensor. Wider than the 40 the endpoint returns, so the board accumulates
// history as the demo runs rather than being capped at whatever one response happened to hold.
const DEFAULT_WINDOW = 80;

// The map is the dedupe ledger, so it necessarily outlives the visible window. Rebuild it from
// the retained rows once it gets far larger than it needs to be. The bound is deliberately well
// above the window: prune too eagerly and we would drop keys the server is still re-sending,
// which would make every poll look like new data and undo the whole point of this file.
const LEDGER_LIMIT = DEFAULT_WINDOW * 10;

const keyOf = (p) => (p.id != null ? `id:${p.id}` : `t:${p.recorded_at}:${p.value}`);

/**
 * Accumulate polled readings into one growing, chart-shaped series.
 *
 * @param points   `series.points` straight off the API, oldest first.
 * @param resetKey changing this throws the history away - switching mine or sensor must not
 *                 splice one site's readings onto another's.
 * @returns rows of `{ x, ts, value, breached }`, oldest first, referentially stable between
 *          polls that bring no new readings.
 *
 * `x` is a sequence number, not a timestamp, and that is deliberate. Readings do not arrive on
 * a regular cadence: the seeded history is hours apart while the live simulator ticks every few
 * seconds, so plotting against real time collapses every live reading into one pixel at the
 * right edge and hands the width to gaps where nothing happened. Sequence spaces readings
 * evenly - the axis still carries their real clock times as labels - and, being numeric rather
 * than categorical, it gives the window a continuous domain to slide along.
 */
export function useLiveSeries(points, { window: windowSize = DEFAULT_WINDOW, resetKey = null } = {}) {
  const store = useRef(null);

  if (store.current === null || store.current.resetKey !== resetKey) {
    store.current = { resetKey, ledger: new Map(), rows: [], next: 0 };
  }
  const s = store.current;

  // Derived during render rather than in an effect, so the first paint after a poll already
  // has the new point. Merging is keyed and therefore idempotent, which is what makes it safe
  // under StrictMode's double invocation.
  let added = false;
  for (const p of points ?? []) {
    const key = keyOf(p);
    if (s.ledger.has(key)) continue;
    const ts = new Date(p.recorded_at).getTime();
    if (Number.isNaN(ts)) continue;
    // Stamped once, on arrival, and never recomputed - so trimming the window or rebuilding
    // the ledger cannot renumber points underneath the chart and jolt it sideways.
    s.ledger.set(key, { key, x: s.next++, ts, value: p.value, breached: p.breached });
    added = true;
  }

  if (added) {
    // Sorted by arrival sequence rather than timestamp, so the series is monotonic in x by
    // construction and the line can never double back on itself.
    const all = [...s.ledger.values()].sort((a, b) => a.x - b.x);
    s.rows = all.length > windowSize ? all.slice(all.length - windowSize) : all;

    if (s.ledger.size > LEDGER_LIMIT) {
      s.ledger = new Map(s.rows.map((r) => [r.key, r]));
    }
  }

  return s.rows;
}

const prefersReducedMotion = () =>
  typeof window !== "undefined" &&
  window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;

/**
 * Ease the x-axis window toward its new position, so a landing reading scrolls the chart
 * instead of teleporting it.
 *
 * This is the half of "live" that Recharts cannot do for us. Its own `isAnimationActive` re-runs
 * the series' draw-in transition on every data change, which is the flashing we are trying to
 * remove - so the line stays static and the VIEWPORT moves instead. The path is never redrawn;
 * it slides out of frame on the left as the domain advances.
 */
export function useSlidingDomain(min, max, { duration = 600 } = {}) {
  const [domain, setDomain] = useState([min, max]);
  const frame = useRef(0);
  const current = useRef([min, max]);

  useEffect(() => {
    if (!Number.isFinite(min) || !Number.isFinite(max) || max <= min) return undefined;

    const [fromMin, fromMax] = current.current;
    const settled = fromMin === min && fromMax === max;

    // First paint, a reset, or a reader who has asked for less motion: adopt the window whole.
    if (settled || prefersReducedMotion() || !Number.isFinite(fromMin)) {
      current.current = [min, max];
      setDomain([min, max]);
      return undefined;
    }

    const start = performance.now();
    cancelAnimationFrame(frame.current);

    const step = (now) => {
      const t = Math.min(1, (now - start) / duration);
      const eased = t * (2 - t); // ease-out quad: quick off the mark, settles without a bounce
      const next = [fromMin + (min - fromMin) * eased, fromMax + (max - fromMax) * eased];
      current.current = next;
      setDomain(next);
      if (t < 1) frame.current = requestAnimationFrame(step);
    };

    frame.current = requestAnimationFrame(step);
    return () => cancelAnimationFrame(frame.current);
  }, [min, max, duration]);

  return domain;
}
