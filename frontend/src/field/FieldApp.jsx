// The offline field app (Phase 7B): /field, for inspectors and mine heads, built for a phone.
//
// After one sign-in with signal, everything here works with none: the app shell comes from the
// service worker, the account, mines, assigned inspections and checklist from IndexedDB, and each
// finding goes into the phone's queue with its photos, location and time. Sync sends the queue
// when there is signal; an expired login keeps the queue and asks to sign in first.
import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, Route, Routes, useNavigate, useParams } from "react-router-dom";
import { LanguageSwitcher } from "../components/common/LanguageSwitcher";
import { setLanguage } from "../i18n";
import { categoryLabel, obligationTitle, severityLabel, violationTypeLabel } from "../i18n/labels";
import { useT } from "../i18n/t";
import { fmtBytes, fmtDate, fmtDateTime, fmtNumber } from "../utils/format";
import { compressPhoto, distanceM, locate, saveCapture, startVisit } from "./capture";
import { queue as readQueue } from "./db";
import { adopt, dropToken, forgetSession, loadSession, refresh, signIn, tokenValid } from "./session";
import { syncQueue } from "./sync";
import { getToken } from "../api/client";

function useOnline() {
  const [online, setOnline] = useState(navigator.onLine);
  useEffect(() => {
    const on = () => setOnline(true);
    const off = () => setOnline(false);
    window.addEventListener("online", on);
    window.addEventListener("offline", off);
    return () => { window.removeEventListener("online", on); window.removeEventListener("offline", off); };
  }, []);
  return online;
}

export default function FieldApp() {
  const t = useT();
  const [session, setSession] = useState(undefined);
  const [items, setItems] = useState({ visits: [], captures: [], photos: [] });

  const reload = useCallback(async () => setItems(await readQueue()), []);

  useEffect(() => {
    (async () => {
      let s = await loadSession();
      // Signed in to the dashboard in this tab already: use that session.
      if (!s && getToken() && navigator.onLine) s = await adopt(getToken()).catch(() => null);
      if (s?.user?.preferred_language) setLanguage(s.user.preferred_language);
      setSession(s);
      await reload();
    })();
  }, [reload]);

  if (session === undefined) return <div className="field-app"><p className="field-pad muted">{t("common.loading")}</p></div>;
  if (!session) return <div className="field-app"><FieldSignIn onSignedIn={setSession} /></div>;
  if (!session.user.permissions?.includes("field.capture")) {
    return <div className="field-app"><div className="field-pad"><p>{t("field.notForRole")}</p><Link to="/">{t("field.toDashboard")}</Link></div></div>;
  }
  const mine = (i) => i.user_id === session.user.id;
  const own = { visits: items.visits.filter(mine), captures: items.captures.filter(mine), photos: items.photos.filter(mine) };
  const others = items.captures.filter((c) => !mine(c) && c.status !== "synced").length;
  const ctx = { session, setSession, items: own, others, reload };

  return (
    <div className="field-app">
      <Routes>
        <Route index element={<FieldHome {...ctx} />} />
        <Route path="visit/:id" element={<FieldVisit {...ctx} />} />
        <Route path="queue" element={<FieldQueue {...ctx} />} />
      </Routes>
    </div>
  );
}

function FieldHeader({ session, title, back }) {
  const t = useT();
  const online = useOnline();
  return (
    <header className="field-header">
      {back ? <Link to={back} className="field-back" aria-label={t("field.back")}>‹</Link> : null}
      <div className="field-title">
        <strong>{title}</strong>
        <span className="small muted">{session.user.full_name}</span>
      </div>
      <span className={`field-net ${online ? "is-online" : "is-offline"}`} id="field-network">{online ? t("field.online") : t("field.offline")}</span>
    </header>
  );
}

function FieldSignIn({ onSignedIn, lockedEmail = null, onCancel = null }) {
  const t = useT();
  const online = useOnline();
  const [email, setEmail] = useState(lockedEmail ?? "");
  const [password, setPassword] = useState("");
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      onSignedIn(await signIn(email.trim(), password));
    } catch (err) {
      setError(!err.response ? t("errors.NETWORK") : err.response.status === 401 ? t("errors.INVALID_CREDENTIALS") : t("errors.UNKNOWN"));
    } finally {
      setBusy(false);
    }
  }
  return (
    <form className="field-pad stack" onSubmit={submit} id="field-sign-in">
      {!lockedEmail && <div className="row"><h1 className="field-h1">{t("field.appName")}</h1><span className="spacer" /><LanguageSwitcher id="field-lang" /></div>}
      <p className="muted small">{lockedEmail ? t("field.signInToSync") : t("field.signInFirst")}</p>
      {!online && <p className="field-warn">{t("field.signInNeedsSignal")}</p>}
      <label className="label" htmlFor="field-email">{t("login.email")}</label>
      <input id="field-email" type="email" autoComplete="username" value={email} readOnly={!!lockedEmail}
             onChange={(e) => setEmail(e.target.value)} required />
      <label className="label" htmlFor="field-password">{t("login.password")}</label>
      <input id="field-password" type="password" autoComplete="current-password" value={password}
             onChange={(e) => setPassword(e.target.value)} required />
      {error && <p className="field-error" role="alert">{error}</p>}
      <button type="submit" className="primary field-big" disabled={busy || !online}>{busy ? t("field.signingIn") : t("login.signIn")}</button>
      {onCancel && <button type="button" onClick={onCancel}>{t("common.cancel")}</button>}
    </form>
  );
}

const counts = (items) => {
  const list = [...items.visits, ...items.captures, ...items.photos];
  const by = (s) => list.filter((i) => i.status === s).length;
  return { pending: by("pending") + by("syncing"), failed: by("failed"), synced: by("synced"), syncing: by("syncing") };
};

function SyncPanel({ session, setSession, items, reload }) {
  const t = useT();
  const online = useOnline();
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState(null);
  const [asking, setAsking] = useState(false);
  const c = counts(items);
  const loginNeeded = !tokenValid(session);

  async function run(current = session) {
    setBusy(true);
    setMessage(null);
    const r = await syncQueue(current, reload);
    await reload();
    setBusy(false);
    if (r.state === "login") {
      setAsking(true);
      setMessage(t("field.sync.login"));
    } else if (r.state === "offline") {
      setMessage(t("field.sync.offline"));
    } else {
      setMessage(t("field.sync.result", { synced: r.synced, replayed: r.replayed, failed: r.failed }));
      refresh(current).then(setSession).catch(() => {});   // assigned inspections may have changed
    }
  }

  if (asking) {
    return (
      <section className="field-card">
        <FieldSignIn lockedEmail={session.user.email} onCancel={() => setAsking(false)}
                     onSignedIn={(s) => { setSession(s); setAsking(false); run(s); }} />
      </section>
    );
  }
  return (
    <section className="field-card" id="field-sync">
      <div className="field-counts">
        <span><b id="count-pending">{c.pending}</b> {t("field.status.pending")}</span>
        <span className={c.failed ? "field-bad" : ""}><b id="count-failed">{c.failed}</b> {t("field.status.failed")}</span>
        <span><b id="count-synced">{c.synced}</b> {t("field.status.synced")}</span>
      </div>
      {loginNeeded && <p className="field-warn small">{t("field.loginExpired")}</p>}
      <div className="row wrap-row">
        <button type="button" className="primary field-big" id="field-sync-button" disabled={busy || !online || c.pending + c.failed === 0}
                onClick={() => (loginNeeded ? setAsking(true) : run())}>
          {busy ? t("field.syncing") : loginNeeded ? t("field.signInAndSync") : t("field.syncNow")}
        </button>
        <Link to="/field/queue" className="btn">{t("field.queue")}</Link>
      </div>
      {!online && c.pending > 0 && <p className="small muted">{t("field.waitingForSignal")}</p>}
      {message && <p className="small" id="field-sync-message" role="status">{message}</p>}
    </section>
  );
}

function FieldHome(props) {
  const { session, setSession, items, others, reload } = props;
  const t = useT();
  const navigate = useNavigate();
  const b = session.bootstrap;
  const [mineId, setMineId] = useState("");
  const mines = useMemo(() => Object.fromEntries(b.mines.map((m) => [m.id, m])), [b.mines]);
  const isHead = session.user.role === "mine_head";

  async function begin(target) {
    const visit = await startVisit(session.user, target);
    await reload();
    navigate(`/field/visit/${visit.client_id}`);
  }
  async function signOut() {
    const unsynced = counts(props.items).pending + counts(props.items).failed;
    if (!window.confirm(unsynced ? t("field.signOutKeeps", { count: unsynced }) : t("field.signOutConfirm"))) return;
    await dropToken(session);
    if (!unsynced) await forgetSession();
    setSession(unsynced ? { ...session, token: null } : null);
  }

  return (
    <>
      <FieldHeader session={session} title={t("field.appName")} />
      <main className="field-pad stack">
        <SyncPanel {...props} />
        {others > 0 && <p className="small muted">{t("field.otherAccounts", { count: others })}</p>}

        <section className="field-card">
          <h2 className="field-h2">{t("field.startVisit")}</h2>
          {!isHead && b.inspections.length > 0 && (
            <ul className="field-list" id="field-assigned">
              {b.inspections.map((i) => (
                <li key={i.id}>
                  <button type="button" className="field-row" onClick={() => begin({ mine_id: i.mine_id, inspection_id: i.id })}>
                    <span><strong>{mines[i.mine_id]?.name}</strong><span className="small muted"> {mines[i.mine_id]?.district}</span></span>
                    <span className="small muted">{t("field.scheduled", { date: fmtDate(i.scheduled_for) })} · {t(`field.inspectionStatus.${i.status}`)}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
          <label className="label" htmlFor="field-mine">{isHead ? t("field.selfInspection") : t("field.unscheduled")}</label>
          <div className="row wrap-row">
            <select id="field-mine" value={mineId} onChange={(e) => setMineId(e.target.value)}>
              <option value="">{t("field.pickMine")}</option>
              {b.mines.map((m) => <option key={m.id} value={m.id}>{m.name} ({m.code})</option>)}
            </select>
            <button type="button" disabled={!mineId} onClick={() => begin({ mine_id: Number(mineId) })} id="field-start-unscheduled">{t("field.start")}</button>
          </div>
        </section>

        {items.visits.length > 0 && (
          <section className="field-card">
            <h2 className="field-h2">{t("field.visitsOnPhone")}</h2>
            <ul className="field-list" id="field-visits">
              {[...items.visits].reverse().map((v) => (
                <li key={v.client_id}>
                  <Link className="field-row" to={`/field/visit/${v.client_id}`}>
                    <span><strong>{mines[v.mine_id]?.name ?? `#${v.mine_id}`}</strong> <StatusChip item={v} /></span>
                    <span className="small muted">{fmtDateTime(v.recorded_at)} · {t("field.captureCount", { count: items.captures.filter((c) => c.visit_client_id === v.client_id).length })}</span>
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        )}

        <footer className="field-foot small muted">
          <span>{t("field.dataFrom", { when: fmtDateTime(session.bootstrapped_at) })}</span>
          <div className="row wrap-row">
            <LanguageSwitcher id="field-lang-home" />
            <Link to="/">{t("field.toDashboard")}</Link>
            <button type="button" className="link-button" onClick={signOut}>{t("shell.signOut")}</button>
          </div>
        </footer>
      </main>
    </>
  );
}

function StatusChip({ item }) {
  const t = useT();
  return (
    <span className={`field-chip is-${item.status}`} data-status={item.status}>
      {item.status === "synced" && item.replayed ? t("field.status.replayed") : t(`field.status.${item.status}`)}
    </span>
  );
}

function FieldVisit(props) {
  const { session, items, reload } = props;
  const t = useT();
  const { id } = useParams();
  const b = session.bootstrap;
  const visit = items.visits.find((v) => v.client_id === id);
  const [open, setOpen] = useState(null);
  if (!visit) return <><FieldHeader session={session} title={t("field.appName")} back="/field" /><p className="field-pad">{t("field.visitMissing")}</p></>;
  const mine = b.mines.find((m) => m.id === visit.mine_id);
  const captures = items.captures.filter((c) => c.visit_client_id === visit.client_id);
  const byCategory = b.categories.map((c) => ({ ...c, items: b.checklist.items.filter((i) => i.category === c.key) }));

  if (open) {
    return <CaptureForm {...props} visit={visit} mine={mine} item={open} onDone={async () => { setOpen(null); await reload(); }} />;
  }
  return (
    <>
      <FieldHeader session={session} title={mine?.name ?? `#${visit.mine_id}`} back="/field" />
      <main className="field-pad stack">
        <p className="small muted">{t("field.visitStarted", { when: fmtDateTime(visit.recorded_at) })} <StatusChip item={visit} /></p>
        {captures.length > 0 && (
          <section className="field-card">
            <h2 className="field-h2">{t("field.findings", { count: captures.length })}</h2>
            <ul className="field-list" id="field-captures">
              {captures.map((c) => <CaptureRow key={c.client_id} capture={c} photos={items.photos} />)}
            </ul>
          </section>
        )}
        <section className="field-card">
          <h2 className="field-h2">{t("field.checklist")}</h2>
          <p className="small muted">{t("field.checklistHint")}</p>
          {byCategory.map((cat) => (
            <details key={cat.key} className="field-cat">
              <summary>{categoryLabel(cat.key)} <span className="small muted">{captures.filter((c) => c.category === cat.key).length || ""}</span></summary>
              <ul className="field-list">
                {cat.items.map((item) => (
                  <li key={item.code}>
                    <button type="button" className="field-row" data-item={item.code} onClick={() => setOpen(item)}>
                      <span>{t(`field.check.${item.code}`)}</span>
                      <span className="small muted">{item.code}{item.obligations.length ? ` · ${item.obligations.join(", ")}` : ""}</span>
                    </button>
                  </li>
                ))}
              </ul>
            </details>
          ))}
        </section>
      </main>
    </>
  );
}

function CaptureRow({ capture, photos }) {
  const t = useT();
  const own = photos.filter((p) => p.capture_client_id === capture.client_id);
  return (
    <li className="field-capture" data-capture={capture.client_id}>
      <div className="row wrap-row">
        <strong>{t(`field.check.${capture.checklist_item}`, { defaultValue: categoryLabel(capture.category) })}</strong>
        <span className="spacer" />
        <StatusChip item={capture} />
      </div>
      <span className="small muted">
        {severityLabel(capture.severity)} · {capture.violation_type ? violationTypeLabel(capture.violation_type) : t("field.observationOnly")}
        {" · "}{t("field.photoCount", { count: own.length })}{own.some((p) => p.status !== "synced") && capture.status === "synced" ? ` (${t("field.photosPending")})` : ""}
      </span>
      {capture.error && <span className="field-error small">{t(`errors.${capture.error.code}`, { defaultValue: capture.error.code, ...(capture.error.params ?? {}) })}</span>}
      {capture.result?.geo_flag && <span className="field-warn small">{t("field.geoFlagged", { km: fmtNumber(capture.result.distance_m / 1000, 1) })}</span>}
    </li>
  );
}

function CaptureForm({ session, visit, mine, item, onDone }) {
  const t = useT();
  const b = session.bootstrap;
  const settings = b.settings;
  const types = b.categories.find((c) => c.key === item.category)?.types ?? [];
  const [severity, setSeverity] = useState("medium");
  const [obligation, setObligation] = useState(item.obligations[0] ?? "");
  const [note, setNote] = useState("");
  const [photos, setPhotos] = useState([]);
  const [where, setWhere] = useState({ status: "locating" });
  const [asViolation, setAsViolation] = useState(true);
  const [type, setType] = useState(types[0] ?? "");
  const [withAction, setWithAction] = useState(false);
  const [action, setAction] = useState("");
  const [dueDays, setDueDays] = useState(7);
  const [busy, setBusy] = useState(false);

  const findMe = useCallback(() => { setWhere({ status: "locating" }); locate().then(setWhere); }, []);
  useEffect(findMe, [findMe]);
  useEffect(() => () => photos.forEach((p) => URL.revokeObjectURL(p.url)), [photos]);

  const distance = where.status === "gps" && mine?.lat != null ? distanceM(where, { lat: mine.lat, lon: mine.lon }) : null;

  async function addPhotos(files) {
    const room = settings.photos_per_capture - photos.length;
    const next = [];
    for (const f of [...files].slice(0, room)) {
      const p = await compressPhoto(f, { maxPx: settings.photo_max_px, quality: settings.photo_quality });
      next.push({ ...p, url: URL.createObjectURL(p.blob) });
    }
    setPhotos((prev) => [...prev, ...next]);
  }

  async function save() {
    setBusy(true);
    const located = where.status === "gps";
    await saveCapture(session.user, visit, {
      category: item.category, checklist_item: item.code, obligation_code: obligation || null, severity, note: note.trim(),
      location_status: located ? "gps" : where.status === "locating" ? "unavailable" : where.status,
      location: located ? { lat: where.lat, lon: where.lon, accuracy_m: where.accuracy_m } : null,
      violation_type: asViolation && type ? type : null,
      corrective_action: asViolation && withAction && action.trim() ? { description: action.trim(), due_days: Number(dueDays) } : null,
    }, photos);
    onDone();
  }

  return (
    <>
      <header className="field-header">
        <button type="button" className="field-back" onClick={onDone} aria-label={t("field.back")}>‹</button>
        <div className="field-title"><strong>{t(`field.check.${item.code}`)}</strong><span className="small muted">{categoryLabel(item.category)} · {item.code}</span></div>
      </header>
      <main className="field-pad stack" id="field-capture-form">
        <fieldset className="field-card">
          <legend className="label">{t("field.severity")}</legend>
          <div className="field-segment">
            {["low", "medium", "high"].map((s) => (
              <button key={s} type="button" className={severity === s ? `is-on sev-${s}` : ""} aria-pressed={severity === s} onClick={() => setSeverity(s)} data-severity={s}>
                {severityLabel(s)}
              </button>
            ))}
          </div>
        </fieldset>

        {item.obligations.length > 0 && (
          <div className="field-card">
            <label className="label" htmlFor="field-obligation">{t("field.obligation")}</label>
            <select id="field-obligation" value={obligation} onChange={(e) => setObligation(e.target.value)}>
              <option value="">{t("field.noObligation")}</option>
              {item.obligations.map((code) => {
                const o = b.obligations.find((x) => x.code === code);
                return <option key={code} value={code}>{code} · {o ? obligationTitle(o) : code}</option>;
              })}
            </select>
            {obligation && (() => { const o = b.obligations.find((x) => x.code === obligation); return o ? <p className="small muted">{o.instrument}, {o.clause}</p> : null; })()}
          </div>
        )}

        <div className="field-card">
          <span className="label">{t("field.photos", { max: settings.photos_per_capture })}</span>
          <div className="field-thumbs">
            {photos.map((p, i) => (
              <figure key={p.url}>
                <img src={p.url} alt={t("field.photoAlt", { n: i + 1 })} />
                <figcaption className="small muted">{fmtBytes(p.blob.size)}</figcaption>
                <button type="button" className="link-button small" onClick={() => setPhotos(photos.filter((x) => x !== p))}>{t("field.remove")}</button>
              </figure>
            ))}
          </div>
          {photos.length < settings.photos_per_capture && (
            <label className="btn field-big" htmlFor="field-photo-input">{t("field.takePhoto")}</label>
          )}
          <input id="field-photo-input" className="sr-only" type="file" accept="image/*" capture="environment" multiple
                 onChange={(e) => { addPhotos(e.target.files); e.target.value = ""; }} />
        </div>

        <div className="field-card" id="field-location">
          <span className="label">{t("field.location")}</span>
          {where.status === "locating" && <p className="small">{t("field.locating")}</p>}
          {where.status === "gps" && (
            <p className="small">
              {t("field.located", { accuracy: fmtNumber(where.accuracy_m, 0) })}
              {distance !== null && <> · {t("field.fromMine", { km: fmtNumber(distance / 1000, 1) })}</>}
            </p>
          )}
          {distance !== null && distance > settings.geo_radius_m && (
            <p className="field-warn small" id="field-geo-warning">{t("field.farFromMine", { km: fmtNumber(settings.geo_radius_m / 1000, 0) })}</p>
          )}
          {(where.status === "denied" || where.status === "unavailable") && <p className="small field-warn">{t(`field.location_${where.status}`)}</p>}
          {where.status === "unknown_underground" && <p className="small">{t("field.location_unknown_underground")}</p>}
          <div className="row wrap-row">
            <button type="button" onClick={findMe}>{t("field.locateAgain")}</button>
            <button type="button" onClick={() => setWhere({ status: "unknown_underground" })} id="field-underground">{t("field.underground")}</button>
          </div>
        </div>

        <div className="field-card">
          <label className="label" htmlFor="field-note">{t("field.note")}</label>
          <textarea id="field-note" rows={3} maxLength={2000} value={note} onChange={(e) => setNote(e.target.value)} />
        </div>

        <div className="field-card stack tight">
          <label className="check-row"><input type="checkbox" checked={asViolation} onChange={(e) => setAsViolation(e.target.checked)} id="field-as-violation" /> {t("field.asViolation")}</label>
          {asViolation && (
            <>
              <select id="field-violation-type" value={type} onChange={(e) => setType(e.target.value)} aria-label={t("field.violationType")}>
                {types.map((v) => <option key={v} value={v}>{violationTypeLabel(v)}</option>)}
              </select>
              <label className="check-row"><input type="checkbox" checked={withAction} onChange={(e) => setWithAction(e.target.checked)} id="field-with-action" /> {t("field.withAction")}</label>
              {withAction && (
                <>
                  <textarea id="field-action" rows={2} maxLength={500} value={action} placeholder={t("field.actionPlaceholder")} onChange={(e) => setAction(e.target.value)} />
                  <label className="label" htmlFor="field-due">{t("field.dueDays")}</label>
                  <input id="field-due" type="number" min={1} max={90} value={dueDays} onChange={(e) => setDueDays(e.target.value)} />
                </>
              )}
            </>
          )}
        </div>

        <p className="small muted">{t("field.savedOnPhone")}</p>
        <button type="button" className="primary field-big" onClick={save} disabled={busy} id="field-save">
          {t("field.save")}
        </button>
      </main>
    </>
  );
}

function FieldQueue(props) {
  const { session, items } = props;
  const t = useT();
  const mines = Object.fromEntries(session.bootstrap.mines.map((m) => [m.id, m]));
  const rows = [
    ...items.visits.map((v) => ({ ...v, kind: "visit", label: mines[v.mine_id]?.name ?? `#${v.mine_id}` })),
    ...items.captures.map((c) => ({ ...c, kind: "capture", label: t(`field.check.${c.checklist_item}`, { defaultValue: categoryLabel(c.category) }) })),
    ...items.photos.map((p) => ({ ...p, kind: "photo", label: fmtBytes(p.bytes) })),
  ].sort((a, b) => a.created_at.localeCompare(b.created_at));
  return (
    <>
      <FieldHeader session={session} title={t("field.queue")} back="/field" />
      <main className="field-pad stack">
        <SyncPanel {...props} />
        <ul className="field-list field-card" id="field-queue">
          {rows.map((r) => (
            <li key={r.client_id} className="field-capture" data-kind={r.kind} data-status={r.status}>
              <div className="row wrap-row">
                <span className="tag">{t(`field.kind.${r.kind}`)}</span>
                <span>{r.label}</span>
                <span className="spacer" />
                <StatusChip item={r} />
              </div>
              {r.error && <span className="field-error small">{r.error.code === "NETWORK" ? t("errors.NETWORK")
                : t(`errors.${r.error.code}`, { defaultValue: r.error.code, ...(r.error.params ?? {}) })}</span>}
              {r.synced_at && <span className="small muted">{t("field.syncedAt", { when: fmtDateTime(r.synced_at) })}</span>}
            </li>
          ))}
          {!rows.length && <li className="muted small">{t("field.queueEmpty")}</li>}
        </ul>
      </main>
    </>
  );
}
