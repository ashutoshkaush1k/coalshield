// One obligation task in a drawer: the obligation with its full citation, the due time and its
// basis, every piece of evidence with its review, and - by role - the mine head's upload form or
// the reviewer's accept / reject (a reason is required to reject); government may waive a task
// for its period, with a reason.
import { useEffect, useState } from "react";
import { assetUrl } from "../../api/client";
import { getObligationTask, reviewEvidence, submitEvidence, waiveTask } from "../../api/obligations";
import { can } from "../../auth/permissions";
import { useAuth } from "../../hooks/useAuth";
import { useT } from "../../i18n/t";
import { ErrorNotice } from "../common/ErrorNotice";
import { Loader } from "../common/Loader";
import { fmtWhen } from "../grievances/common";
import { Drawer } from "../overlay/Overlay";
import { useToast } from "../overlay/ToastHost";
import { Citation } from "./Citation";
import { frequencyLabel, obligationTitle } from "../../i18n/labels";
import { TaskStatus } from "./TaskList";

const AWAITING_EVIDENCE = ["open", "rejected", "overdue", "escalated"];

export function TaskDrawer({ taskId, onClose, onChanged }) {
  const t = useT();
  const { user } = useAuth();
  const { notify } = useToast();
  const [task, setTask] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [file, setFile] = useState(null);
  const [note, setNote] = useState("");
  const [reason, setReason] = useState("");
  const [waiver, setWaiver] = useState("");

  useEffect(() => {
    let live = true;
    setTask(null); setError(null);
    getObligationTask(taskId).then((d) => live && setTask(d)).catch((e) => live && setError(e));
    return () => { live = false; };
  }, [taskId]);

  const act = async (call, toast) => {
    setBusy(true); setError(null);
    try {
      setTask(await call());
      setFile(null); setNote(""); setReason(""); setWaiver("");
      notify({ title: toast });
      onChanged?.();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  const o = task?.obligation;
  const pending = task?.submissions?.find((s) => s.status === "pending");
  const waived = task?.status === "waived" ? task.history?.find((h) => h.to_status === "waived") : null;
  return (
    <Drawer open onClose={onClose} title={task ? t("obligation.task.title", { code: o.code, period: task.period }) : t("obligation.loading")}
            subtitle={task ? `${task.mine_name} · ${obligationTitle(o)}` : ""}>
      {!task && !error && <Loader label={t("obligation.loading")} />}
      <ErrorNotice error={error} />
      {task && (
        <div className="stack" id="obligation-task">
          <TaskStatus task={task} />
          <section className="citation-block">
            <Citation obligation={o} />
          </section>
          <dl className="detail-grid">
            <dt>{t("obligation.list.due")}</dt><dd>{fmtWhen(task.due_at)} <span className="faint small">({t(`obligation.dueBasis.${task.due_basis}`)})</span></dd>
            <dt>{t("obligation.task.evidenceType")}</dt><dd>{t(`obligationEvidence.${o.code}`, { defaultValue: o.evidence_type })}</dd>
            <dt>{t("obligation.task.frequency")}</dt><dd>{frequencyLabel(o.frequency)}</dd>
            {o.due_rule && <><dt>{t("obligation.task.dueRule")}</dt><dd className="small">{o.due_rule}</dd></>}
          </dl>

          {task.incident && (
            <p className="notice" id="obligation-incident">
              {t("obligation.incident.detail", { id: task.incident.id, occurred: fmtWhen(task.incident.occurred_at), reported: fmtWhen(task.incident.reported_at) })}
              {" "}<strong>{t(task.reported_late ? "obligation.incident.late" : "obligation.incident.onTime")}</strong>
            </p>
          )}

          {waived && (
            <p className="notice" id="obligation-waiver">
              {t("obligation.task.waivedBy", { name: waived.user_name ?? "-", when: fmtWhen(waived.created_at) })}
              {waived.context?.reason && <> - <em>{waived.context.reason}</em></>}
            </p>
          )}

          {can(user, "obligation.submit") && AWAITING_EVIDENCE.includes(task.status) && (
            <section className="stack tight" id="obligation-upload">
              <span className="label">{t("obligation.task.upload")}</span>
              <label htmlFor="ob-file">{t("obligation.task.file")}</label>
              <input id="ob-file" type="file" accept="application/pdf,image/jpeg,image/png,image/webp" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
              <label htmlFor="ob-note">{t("obligation.task.note")}</label>
              <textarea id="ob-note" rows={2} value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} />
              <button type="button" className="primary" disabled={busy || !file}
                      onClick={() => act(() => submitEvidence(task.id, { file, note }), t("obligation.task.submitted"))}>
                {t("obligation.task.submit")}
              </button>
            </section>
          )}

          {can(user, "obligation.review") && pending && (
            <section className="stack tight" id="obligation-review">
              <label htmlFor="ob-reason">{t("obligation.task.reason")}</label>
              <textarea id="ob-reason" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} />
              <div className="row wrap-row">
                <button type="button" className="primary" disabled={busy}
                        onClick={() => act(() => reviewEvidence(pending.id, "accept", reason), t("obligation.task.reviewed"))}>{t("obligation.task.accept")}</button>
                <button type="button" disabled={busy}
                        onClick={() => act(() => reviewEvidence(pending.id, "reject", reason), t("obligation.task.reviewed"))}>{t("obligation.task.reject")}</button>
              </div>
            </section>
          )}

          {can(user, "obligation.waive") && AWAITING_EVIDENCE.includes(task.status) && (
            <details className="stack tight" id="obligation-waive">
              <summary>{t("obligation.task.waiveTitle")}</summary>
              <p className="note">{t("obligation.task.waiveHint")}</p>
              <label htmlFor="ob-waiver">{t("obligation.task.waiveReason")}</label>
              <textarea id="ob-waiver" rows={2} value={waiver} onChange={(e) => setWaiver(e.target.value)} maxLength={1000} />
              <div>
                <button type="button" disabled={busy}
                        onClick={() => act(() => waiveTask(task.id, waiver), t("obligation.task.waivedToast"))}>{t("obligation.task.waive")}</button>
              </div>
            </details>
          )}

          <section>
            <span className="label">{t("obligation.task.submissions")}</span>
            {task.submissions?.length ? (
              <ol className="timeline">
                {task.submissions.map((s) => (
                  <li key={s.id} data-submission={s.id}>
                    <strong>{t(`status.${s.status}`)}</strong>
                    <span className="faint small"> · {t("obligation.task.submittedBy", { name: s.submitter_name ?? "-", when: fmtWhen(s.submitted_at) })}</span>
                    {s.note && <div className="small">{s.note}</div>}
                    <div className="small">
                      {s.file_url ? <a href={assetUrl(s.file_url)} target="_blank" rel="noreferrer">{t("obligation.task.openFile")}</a>
                        : <span className="faint">{t("obligation.task.noFile")}</span>}
                    </div>
                    {s.reviewed_at && (
                      <div className="small">
                        {t("obligation.task.reviewedBy", { name: s.reviewer_name ?? "-", when: fmtWhen(s.reviewed_at) })}
                        {s.review_note && <> - <em>{s.review_note}</em></>}
                      </div>
                    )}
                  </li>
                ))}
              </ol>
            ) : <p className="faint">{t("obligation.task.noSubmissions")}</p>}
          </section>
        </div>
      )}
    </Drawer>
  );
}
