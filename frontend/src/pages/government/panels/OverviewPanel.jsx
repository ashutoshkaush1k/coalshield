// Fleet-wide glance: one hero figure, compact secondary stats, then the core board.
import { CoreSampleBoard } from "../../../components/compliance/CoreSampleBoard";
import { StateFilter } from "../../../components/common/StateFilter";
import { DemoTag } from "../../../components/common/DemoTag";
import { breachesLabel, fmtNumber } from "../../../utils/format";
import { t } from "../../../i18n/t";
import { scopeWhere } from "../../../i18n/labels";
import { useAuth } from "../../../hooks/useAuth";
import { ContractorSummaryCard } from "./ContractorsPanel";

function Tally({ label, value, tone }) {
  return (
    <div>
      <span className="label">{label}</span>
      <span className={`tally-v${tone ? ` risk-${tone}` : ""}`}>{typeof value === "number" ? fmtNumber(value) : value}</span>
    </div>
  );
}

export function OverviewPanel({ data, contractorSummary, state, onStateChange, onOpenContractors }) {
  const stats = data?.stats;
  const { user } = useAuth();
  const scope = state ?? t(`scope.${data?.scope_label_code ?? "NATIONAL"}`);
  const truncated = data?.is_truncated;

  return (
    <div className="stack">
      <section className="panel-block">
        <div className="panel-body">
          <div className="hero-figure">
            <div>
              {/* The label names the scope, so a number can never be read as national
                  when it is actually one state's. */}
              <span className="label">{t("overview.averageLabel", { scope })} <DemoTag /></span>
              <div className="hero-number">{stats?.average_score == null ? "-" : fmtNumber(stats.average_score)}</div>
              <p className="hero-caption">
                {state
                  ? t("overview.meanOfState", { n: fmtNumber(stats?.mine_count ?? 0, 0), state })
                  : t("overview.meanOfScope", { n: fmtNumber(stats?.mine_count ?? 0, 0), where: scopeWhere(user) })}
              </p>
            </div>

            <div className="spacer" />

            <div className="tally-set">
              <Tally label={t("risk.high")} value={stats?.high_risk_count ?? 0} tone="high" />
              <Tally label={t("risk.medium")} value={stats?.medium_risk_count ?? 0} tone="medium" />
              <Tally label={t("risk.low")} value={stats?.low_risk_count ?? 0} tone="low" />
              <Tally label={t("overview.violations")} value={stats?.total_violations ?? 0} />
              <Tally label={breachesLabel(stats?.breach_window_hours)}
                     value={stats?.total_breaches ?? 0} />
            </div>
          </div>
        </div>
      </section>

      <section className="panel-block">
        <div className="panel-head">
          <div>
            <h2>{t("board.title")} <DemoTag /></h2>
            <span className="hint">{t("board.hint")}</span>
          </div>
          <div className="spacer" />
          <StateFilter states={data?.states} value={state} onChange={onStateChange} />
        </div>

        <div className="panel-body">
          {/* Says plainly that a national board is a ranking, not the whole country. */}
          <p className="board-scope">
            {truncated ? (
              <>
                <strong>{t("board.topN", { n: fmtNumber(data.showing, 0), where: scopeWhere(user) })}</strong>{" "}
                {t("board.ofMonitored", { total: fmtNumber(stats?.mine_count ?? 0, 0) })}
              </>
            ) : state ? (
              <><strong>{t("board.allInState", { n: fmtNumber(data?.showing ?? 0, 0), state })}</strong> {t("board.worstFirst")}</>
            ) : (
              <><strong>{t("board.all", { n: fmtNumber(data?.showing ?? 0, 0) })}</strong> {t("board.worstFirst")}</>
            )}
          </p>

          <CoreSampleBoard mines={data?.mines} />
        </div>
      </section>
      <ContractorSummaryCard data={contractorSummary} onOpen={onOpenContractors} />
    </div>
  );
}
