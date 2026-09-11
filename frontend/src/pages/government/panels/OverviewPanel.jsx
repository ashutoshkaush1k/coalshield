// Fleet-wide glance: one hero figure, compact secondary stats, then the core board.
import { CoreSampleBoard } from "../../../components/compliance/CoreSampleBoard";
import { StateFilter } from "../../../components/common/StateFilter";

function Tally({ label, value, tone }) {
  return (
    <div>
      <span className="label">{label}</span>
      <span className={`tally-v${tone ? ` risk-${tone}` : ""}`}>{value}</span>
    </div>
  );
}

export function OverviewPanel({ data, state, onStateChange }) {
  const stats = data?.stats;
  const scope = data?.scope_label ?? "National";
  const truncated = data?.is_truncated;

  return (
    <div className="stack">
      <section className="panel-block">
        <div className="panel-body">
          <div className="hero-figure">
            <div>
              {/* The label names the scope, so a number can never be read as national
                  when it is actually one state's. */}
              <span className="label">{scope} average compliance</span>
              <div className="hero-number">{stats?.average_score ?? "-"}</div>
              <p className="hero-caption">
                Mean score across {stats?.mine_count ?? 0} monitored mines
                {state ? ` in ${state}` : " nationwide"}.
              </p>
            </div>

            <div className="spacer" />

            <div className="tally-set">
              <Tally label="High risk" value={stats?.high_risk_count ?? 0} tone="high" />
              <Tally label="Medium risk" value={stats?.medium_risk_count ?? 0} tone="medium" />
              <Tally label="Low risk" value={stats?.low_risk_count ?? 0} tone="low" />
              <Tally label="Violations" value={stats?.total_violations ?? 0} />
              <Tally label="Breaches" value={stats?.total_breaches ?? 0} />
            </div>
          </div>
        </div>
      </section>

      <section className="panel-block">
        <div className="panel-head">
          <div>
            <h2>Core sample board</h2>
            <span className="hint">
              Each core is filled to its compliance score, worst on the left
            </span>
          </div>
          <div className="spacer" />
          <StateFilter states={data?.states} value={state} onChange={onStateChange} />
        </div>

        <div className="panel-body">
          {/* Says plainly that a national board is a ranking, not the whole country. */}
          <p className="board-scope">
            {truncated ? (
              <>
                <strong>Top {data.showing} highest-risk mines nationally</strong> of{" "}
                {stats?.mine_count ?? 0} monitored. Select a region to see every mine there.
              </>
            ) : state ? (
              <>
                <strong>All {data?.showing ?? 0} monitored mines in {state}</strong>, worst first.
              </>
            ) : (
              <><strong>All {data?.showing ?? 0} monitored mines</strong>, worst first.</>
            )}
          </p>

          <CoreSampleBoard mines={data?.mines} />
        </div>
      </section>
    </div>
  );
}
