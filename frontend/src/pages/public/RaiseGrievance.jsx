// Public page (no login): raise a grievance (brief Phase 5). Linked from the login page.
// The API rate-limits it, checks the attachment's type and size, and rejects the form when the
// hidden honeypot field is filled - people never see that field; simple bots fill every field.
import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { CATEGORIES, LANGUAGES, SAFETY_CATEGORIES, SUBMITTER_TYPES, listPublicMines, submitGrievance } from "../../api/grievances";
import { ErrorNotice } from "../../components/common/ErrorNotice";
import { DemoFooter } from "../../components/common/DemoFooter";
import { categoryLabel } from "../../i18n/labels";
import { useT } from "../../i18n/t";
import { fmtWhen } from "../../components/grievances/common";

const blank = { mine_id: "", submitter_type: "contract_worker", is_anonymous: false, name: "", contact: "", category: "",
  safety_category: "", against_mine_head: false, language: "en", description: "", latitude: "", longitude: "", website: "" };

export default function RaiseGrievance() {
  const t = useT();
  const [mines, setMines] = useState([]);
  const [form, setForm] = useState(blank);
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [done, setDone] = useState(null);
  const [locating, setLocating] = useState("");

  useEffect(() => { listPublicMines().then(setMines).catch(setError); }, []);
  const byState = useMemo(() => mines.reduce((acc, m) => ({ ...acc, [m.state]: [...(acc[m.state] ?? []), m] }), {}), [mines]);
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.type === "checkbox" ? e.target.checked : e.target.value });

  const locate = () => {
    if (!navigator.geolocation) { setLocating("failed"); return; }
    setLocating("busy");
    navigator.geolocation.getCurrentPosition(
      (p) => { setForm((f) => ({ ...f, latitude: p.coords.latitude.toFixed(6), longitude: p.coords.longitude.toFixed(6) })); setLocating("done"); },
      () => setLocating("failed"), { timeout: 10000 });
  };

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setError(null);
    try {
      const fields = { ...form };
      if (fields.is_anonymous) { fields.name = ""; fields.contact = ""; }
      if (fields.category !== "safety") delete fields.safety_category;
      setDone(await submitGrievance(fields, file));
      setForm(blank); setFile(null);
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="login-wrap">
      <div className="public-card stack">
        <div className="brandline">
          <h1>{t("grievance.public.title")}</h1>
          <span>{t("grievance.public.hint")}</span>
        </div>

        {done ? (
          <section className="panel-block" id="grievance-done">
            <div className="panel-body stack">
              <h2>{t("grievance.public.doneTitle")}</h2>
              <span className="label">{t("grievance.public.doneTicket")}</span>
              <div className="ticket-no mono" id="grievance-ticket">{done.ticket_no}</div>
              <p>{t("grievance.public.doneHint")} {t("grievance.public.doneDue", { when: fmtWhen(done.sla_due_at) })}</p>
              <div className="row wrap-row">
                <Link className="btn primary" to={`/grievance/track?ticket=${encodeURIComponent(done.ticket_no)}`}>{t("grievance.public.trackThis")}</Link>
                <button type="button" onClick={() => setDone(null)}>{t("grievance.public.another")}</button>
              </div>
            </div>
          </section>
        ) : (
          <form onSubmit={submit} className="panel-block" id="grievance-form">
            <div className="panel-body stack">
              <div>
                <label htmlFor="gr-mine">{t("grievance.public.mine")}</label>
                <select id="gr-mine" value={form.mine_id} onChange={set("mine_id")} required>
                  <option value="">{t("grievance.public.chooseMine")}</option>
                  {Object.keys(byState).sort().map((state) => (
                    <optgroup key={state} label={state}>
                      {byState[state].map((m) => <option key={m.id} value={m.id}>{m.name} ({m.district})</option>)}
                    </optgroup>
                  ))}
                </select>
              </div>
              <div className="form-grid">
                <div>
                  <label htmlFor="gr-type">{t("grievance.public.submitterType")}</label>
                  <select id="gr-type" value={form.submitter_type} onChange={set("submitter_type")}>
                    {SUBMITTER_TYPES.map((s) => <option key={s} value={s}>{t(`grievance.submitter.${s}`)}</option>)}
                  </select>
                </div>
                <div>
                  <label htmlFor="gr-language">{t("grievance.public.language")}</label>
                  <select id="gr-language" value={form.language} onChange={set("language")}>
                    {LANGUAGES.map((l) => <option key={l} value={l}>{t(`grievance.language.${l}`)}</option>)}
                  </select>
                </div>
              </div>
              <label className="check-row"><input type="checkbox" id="gr-anonymous" checked={form.is_anonymous} onChange={set("is_anonymous")} /> {t("grievance.public.anonymous")}</label>
              {!form.is_anonymous && (
                <div className="form-grid">
                  <div><label htmlFor="gr-name">{t("grievance.public.name")}</label><input id="gr-name" value={form.name} onChange={set("name")} maxLength={120} autoComplete="name" /></div>
                  <div><label htmlFor="gr-contact">{t("grievance.public.contact")}</label><input id="gr-contact" value={form.contact} onChange={set("contact")} maxLength={64} autoComplete="tel" /></div>
                </div>
              )}
              <div className="form-grid">
                <div>
                  <label htmlFor="gr-category">{t("grievance.public.category")}</label>
                  <select id="gr-category" value={form.category} onChange={set("category")} required>
                    <option value="">-</option>
                    {CATEGORIES.map((c) => <option key={c} value={c}>{t(`grievance.category.${c}`)}</option>)}
                  </select>
                </div>
                {form.category === "safety" && (
                  <div>
                    <label htmlFor="gr-safety">{t("grievance.public.safetyCategory")}</label>
                    <select id="gr-safety" value={form.safety_category} onChange={set("safety_category")} required>
                      <option value="">-</option>
                      {SAFETY_CATEGORIES.map((c) => <option key={c} value={c}>{categoryLabel(c)}</option>)}
                    </select>
                  </div>
                )}
              </div>
              <label className="check-row"><input type="checkbox" id="gr-against" checked={form.against_mine_head} onChange={set("against_mine_head")} /> {t("grievance.public.againstMineHead")}</label>
              {(form.category === "harassment" || form.against_mine_head) && <div className="notice">{t("grievance.public.sensitiveNote")}</div>}
              <div>
                <label htmlFor="gr-description">{t("grievance.public.description")}</label>
                <textarea id="gr-description" rows={5} value={form.description} onChange={set("description")} minLength={10} maxLength={4000} required lang={form.language} />
                <span className="faint small">{t("grievance.public.descriptionHint")}</span>
              </div>
              <div>
                <label htmlFor="gr-file">{t("grievance.public.file")}</label>
                <input id="gr-file" type="file" accept="image/jpeg,image/png,application/pdf" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
              </div>
              <div className="row wrap-row">
                <button type="button" onClick={locate} disabled={locating === "busy"}>{t("grievance.public.location")}</button>
                {locating === "done" && <span className="small">{t("grievance.public.locationAdded")} ({form.latitude}, {form.longitude})</span>}
                {locating === "failed" && <span className="small faint">{t("grievance.public.locationFailed")}</span>}
              </div>
              {/* Honeypot: off-screen, skipped by keyboard and screen readers. */}
              <div className="hp-field" aria-hidden="true">
                <label htmlFor="gr-website">{t("grievance.public.honeypot")}</label>
                <input id="gr-website" name="website" tabIndex={-1} autoComplete="off" value={form.website} onChange={set("website")} />
              </div>
              <ErrorNotice error={error} />
              <button className="primary" type="submit" disabled={busy}>{busy ? t("grievance.public.submitting") : t("grievance.public.submit")}</button>
            </div>
          </form>
        )}
        <div className="row wrap-row public-links">
          <Link to="/grievance/track">{t("login.trackGrievance")}</Link>
          <Link to="/login">{t("grievance.public.backToLogin")}</Link>
        </div>
        <DemoFooter />
      </div>
    </div>
  );
}
