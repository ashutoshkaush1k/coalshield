// Public page (no login): follow a grievance by its ticket number - status and the public
// timeline only (no text, no people), as the API serves it.
import { useEffect, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { trackGrievance } from "../../api/grievances";
import { DemoFooter } from "../../components/common/DemoFooter";
import { ErrorNotice } from "../../components/common/ErrorNotice";
import { GrievanceStatus, fmtWhen } from "../../components/grievances/common";
import { useT } from "../../i18n/t";

export default function TrackGrievance() {
  const t = useT();
  const [params, setParams] = useSearchParams();
  const [ticket, setTicket] = useState(params.get("ticket") ?? "");
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const look = async (value) => {
    if (!value.trim()) return;
    setBusy(true); setError(null); setResult(null);
    try {
      setResult(await trackGrievance(value));
      setParams({ ticket: value.trim().toUpperCase() }, { replace: true });
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };
  useEffect(() => { if (params.get("ticket")) look(params.get("ticket")); }, []); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div className="login-wrap">
      <div className="public-card stack">
        <div className="brandline">
          <h1>{t("grievance.track.title")}</h1>
          <span>{t("grievance.track.hint")}</span>
        </div>
        <form className="panel-block" onSubmit={(e) => { e.preventDefault(); look(ticket); }}>
          <div className="panel-body row wrap-row">
            <label htmlFor="track-ticket" className="label">{t("grievance.track.ticket")}</label>
            <input id="track-ticket" className="mono" value={ticket} onChange={(e) => setTicket(e.target.value)} placeholder="GRV-2026-000123" required />
            <button className="primary" type="submit" disabled={busy}>{t("grievance.track.submit")}</button>
          </div>
        </form>
        {error && (error.isNotFound ? <div className="notice error">{t("grievance.track.notFound")}</div> : <ErrorNotice error={error} />)}
        {result && (
          <section className="panel-block" id="track-result">
            <div className="panel-body stack">
              <div className="row wrap-row">
                <span className="ticket-no mono">{result.ticket_no}</span>
                <GrievanceStatus status={result.status} />
                <span className="tag">{t(`grievance.category.${result.category}`)}</span>
              </div>
              <dl className="detail-grid">
                <dt>{t("grievance.track.raised")}</dt><dd>{fmtWhen(result.created_at)}</dd>
                <dt>{t("grievance.track.due")}</dt><dd>{fmtWhen(result.sla_due_at)}</dd>
                {result.resolution_note && <><dt>{t("grievance.track.outcome")}</dt><dd>{result.resolution_note}</dd></>}
              </dl>
              {result.is_overdue && <div className="notice error">{t("grievance.track.overdue")}</div>}
              <span className="label">{t("grievance.track.timeline")}</span>
              <ol className="timeline">
                {result.timeline.map((step, i) => (
                  <li key={i}><strong>{t(`grievance.action.${step.action}`)}</strong><span className="faint small"> · {fmtWhen(step.at)}</span></li>
                ))}
              </ol>
            </div>
          </section>
        )}
        <div className="row wrap-row public-links">
          <Link to="/grievance">{t("login.raiseGrievance")}</Link>
          <Link to="/login">{t("grievance.public.backToLogin")}</Link>
        </div>
        <DemoFooter />
      </div>
    </div>
  );
}
