// Where risk is accelerating, and what kind of risk it is.
import { BREACH_CATEGORIES, getBreachBuckets } from "../../../api/sensors";
import { BreachLegend, BreachTrendChart } from "../../../components/charts/BreachTrendChart";
import { ErrorNotice } from "../../../components/common/ErrorNotice";
import { Loader } from "../../../components/common/Loader";
import { usePolling } from "../../../hooks/usePolling";

const LABEL = { gas: "gas", dust: "dust", temperature: "temperature" };

/**
 * The category behind a spike, when there is one.
 *
 * "Dominant" means the largest category and at least half the window's breaches. A bar
 * split 2/1/1 has a largest category but no story worth telling; 3/1/0 does.
 */
export function dominantCategory(bucket) {
  if (!bucket?.breaches) return null;
  const ranked = BREACH_CATEGORIES
    .map((c) => ({ category: c, count: bucket[c] ?? 0 }))
    .sort((a, b) => b.count - a.count);
  const [top, second] = ranked;
  if (top.count < bucket.breaches / 2) return null;
  if (second && top.count === second.count) return null;
  return top.category;
}

export function TrendsPanel({ state = null, mineId = null, title, caption }) {
  // One request regardless of how many mines are in scope - the bucketing happens
  // server-side, so a national view is no more expensive than a single mine.
  const { data, error, loading } = usePolling(() => getBreachBuckets({ state, mineId }), {
    deps: [state, mineId],
  });

  if (loading && !data) return <Loader label="Reading sensor history..." />;

  const buckets = data ?? [];
  const recent = buckets.slice(-2);
  const accelerating = recent.length === 2 && recent[1].breaches > recent[0].breaches;
  const peak = buckets.reduce((worst, b) => (b.breaches > (worst?.breaches ?? -1) ? b : worst), null);
  const driver = dominantCategory(peak);

  return (
    <div className="stack">
      <ErrorNotice error={error} />

      <section className="panel-block">
        <div className="panel-head">
          <div>
            <h2>{title}</h2>
            <span className="hint">Threshold breaches per 6-hour window, by sensor</span>
          </div>
          <div className="spacer" />
          <BreachLegend />
        </div>

        <div className="panel-body">
          <BreachTrendChart buckets={buckets} />

          <p className="note" style={{ marginTop: "var(--space-4)" }}>
            Each bar counts gas, dust and temperature readings that crossed their safe limit in
            that window, stacked so the segments add up to the window total. {caption} A spike
            means conditions deteriorated quickly rather than drifting, which is what separates an
            incident from ordinary wear - it is the pattern worth sending an inspector for.
            {peak
              ? driver
                ? ` The worst window so far is ${peak.label} with ${peak.breaches} breaches, and it was ${LABEL[driver]}-driven.`
                : ` The worst window so far is ${peak.label} with ${peak.breaches} breaches, spread across more than one sensor.`
              : ""}
            {accelerating
              ? " Breach frequency is currently rising window on window."
              : " Breach frequency is not currently rising."}
          </p>
        </div>
      </section>
    </div>
  );
}
