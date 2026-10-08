// Renders an API failure (api/client.js shape: {status, code, params, fields, message}) as
// readable guidance rather than a raw error object.
//
// 403 and 404 get their own wording on purpose: for a single-mine account they are the access
// model working (out-of-scope records answer 404, exactly like missing ones), not a crash.
import { useT } from "../../i18n/t";

export function ErrorNotice({ error, context }) {
  const t = useT();
  if (!error || error.isDemoEnded) return null;

  if (error.isForbidden || error.isNotFound) {
    return (
      <div className="notice error">
        <strong>{t(error.isForbidden ? "errorNotice.forbidden" : "errorNotice.notFound")}</strong>{" "}
        {context || error.message}
        <div className="small" style={{ marginTop: 6, opacity: 0.85 }}>{t("errorNotice.serverEnforced")}</div>
      </div>
    );
  }

  return (
    <div className="notice error">
      <strong>{t(error.isNetwork ? "errorNotice.network" : "errorNotice.failed")}</strong>{" "}
      {error.message}
    </div>
  );
}
