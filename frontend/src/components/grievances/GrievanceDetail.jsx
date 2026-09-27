// One grievance in a drawer: the text as written (with its language), who submitted it - only
// when the API sends the identity (it never does to a mine head) - the sensitive-routing notice,
// attachment, location, timeline, and for staff who may manage it: status changes with a note
// (resolving needs one, shown to the complainant) and assignment.
import { useEffect, useState } from "react";
import { NEXT, assignGrievance, getAssignees, getGrievance, transitionGrievance } from "../../api/grievances";
import { assetUrl } from "../../api/client";
import { can } from "../../auth/permissions";
import { useAuth } from "../../hooks/useAuth";
import { useT } from "../../i18n/t";
import { ErrorNotice } from "../common/ErrorNotice";
import { Loader } from "../common/Loader";
import { Drawer } from "../overlay/Overlay";
import { useToast } from "../overlay/ToastHost";
import { GrievanceFlags, GrievanceStatus, LanguageTag, fmtWhen } from "./common";

export function GrievanceDetail({ grievanceId, onClose, onChanged }) {
  const t = useT();
  const { user } = useAuth();
  const { notify } = useToast();
  const [g, setG] = useState(null);
  const [error, setError] = useState(null);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [assignees, setAssignees] = useState([]);
  const [assignee, setAssignee] = useState("");
  const manage = can(user, "grievance.manage");

  useEffect(() => {
    let live = true;
    setG(null); setError(null);
    getGrievance(grievanceId).then((data) => live && setG(data)).catch((e) => live && setError(e));
    if (manage) getAssignees(grievanceId).then((a) => live && setAssignees(a)).catch(() => {});
    return () => { live = false; };
  }, [grievanceId, manage]);

  const act = async (call, toast) => {
    setBusy(true); setError(null);
    try {
      const updated = await call();
      setG(updated); setNote("");
      notify({ title: toast });
      onChanged?.();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Drawer open onClose={onClose} title={g ? t("grievance.detail.title", { ticket: g.ticket_no }) : t("grievance.loading")}
            subtitle={g ? `${g.mine_name} · ${t(`grievance.category.${g.category}`)}` : ""}>
      {!g && !error && <Loader label={t("grievance.loading")} />}
      <ErrorNotice error={error} />
      {g && (
        <div className="stack" id="grievance-detail">
          <div className="row wrap-row"><GrievanceStatus status={g.status} /><GrievanceFlags g={g} /><LanguageTag language={g.language} /></div>
          {g.is_sensitive && <div className="notice">{t("grievance.detail.routedToRegulator")}</div>}

          <section>
            <span className="label">{t("grievance.detail.description")}</span>
            <p className="grievance-text" lang={g.language}>{g.description}</p>
            <span className="faint small">{t("grievance.languageTag", { language: t(`grievance.language.${g.language}`) })}</span>
          </section>

          <dl className="detail-grid">
            <dt>{t("grievance.detail.submitter")}</dt>
            <dd>
              {g.is_anonymous ? t("grievance.detail.anonymous") : (
                <>
                  {t(`grievance.submitter.${g.submitter_type}`)}
                  {" · "}
                  {"name" in g ? <span id="grievance-identity">{[g.name, g.contact].filter(Boolean).join(", ") || "-"}</span>
                    : <span className="faint">{t("grievance.detail.identityHidden")}</span>}
                </>
              )}
            </dd>
            <dt>{t("grievance.list.raised")}</dt><dd>{fmtWhen(g.created_at)}</dd>
            <dt>{t("grievance.list.due")}</dt><dd>{fmtWhen(g.sla_due_at)}</dd>
            <dt>{t("grievance.list.assignee")}</dt><dd>{g.assignee_name ?? "-"}</dd>
            {g.file_url && <><dt>{t("grievance.detail.attachment")}</dt><dd><a href={assetUrl(g.file_url)} target="_blank" rel="noreferrer">{t("grievance.detail.attachment")}</a></dd></>}
            {g.location && <><dt>{t("grievance.detail.location")}</dt><dd className="mono">{g.location.coordinates[1].toFixed(4)}, {g.location.coordinates[0].toFixed(4)}</dd></>}
            {g.resolution_note && <><dt>{t("grievance.detail.resolution")}</dt><dd>{g.resolution_note}</dd></>}
            {g.satisfaction_rating && <><dt /><dd>{t("grievance.detail.rating", { rating: g.satisfaction_rating })}</dd></>}
          </dl>

          {manage && (NEXT[g.status] ?? []).length > 0 && (
            <section className="stack tight" id="grievance-actions">
              <label htmlFor="grievance-note">{(NEXT[g.status] ?? []).includes("resolved") ? t("grievance.detail.resolutionNote") : t("grievance.detail.note")}</label>
              <textarea id="grievance-note" rows={3} value={note} onChange={(e) => setNote(e.target.value)} maxLength={2000} />
              <div className="row wrap-row">
                {NEXT[g.status].map((to) => (
                  <button key={to} type="button" className={to === "resolved" ? "primary" : ""} disabled={busy}
                          onClick={() => act(() => transitionGrievance(g.id, to, note), t("grievance.detail.changed"))}>
                    {t(`grievance.to.${to}`)}
                  </button>
                ))}
              </div>
            </section>
          )}
          {manage && assignees.length > 0 && (
            <section className="row wrap-row">
              <label htmlFor="grievance-assignee" className="label">{t("grievance.detail.assign")}</label>
              <select id="grievance-assignee" value={assignee} onChange={(e) => setAssignee(e.target.value)}>
                <option value="">-</option>
                {assignees.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select>
              <button type="button" disabled={!assignee || busy}
                      onClick={() => act(() => assignGrievance(g.id, Number(assignee)), t("grievance.detail.assigned"))}>
                {t("grievance.detail.assignSubmit")}
              </button>
            </section>
          )}

          <section>
            <span className="label">{t("grievance.detail.timeline")}</span>
            <ol className="timeline">
              {(g.timeline ?? []).map((a) => (
                <li key={a.id}>
                  <strong>{t(`grievance.action.${a.action}`)}</strong>
                  <span className="faint small"> · {fmtWhen(a.created_at)}{a.actor_name ? ` · ${a.actor_name}` : ""}</span>
                  {a.note && a.action !== "escalate" && <div className="small">{a.note}</div>}
                </li>
              ))}
            </ol>
          </section>
        </div>
      )}
    </Drawer>
  );
}
