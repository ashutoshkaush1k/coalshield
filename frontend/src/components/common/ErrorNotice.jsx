// Renders an API failure as readable guidance rather than a raw error object.
//
// 403 gets its own wording on purpose: for a Mine Head it is the access model working correctly
// (PRD 4.2), not a fault, and it should read that way instead of looking like a crash.
export function ErrorNotice({ error, context }) {
  if (!error) return null;

  if (error.isForbidden) {
    return (
      <div className="notice error">
        <strong>Access restricted.</strong>{" "}
        {context || "This data belongs to another mine and is not available to your account."}
        <div className="small" style={{ marginTop: 6, opacity: 0.85 }}>
          Access is enforced by the server, not hidden in this page.
        </div>
      </div>
    );
  }

  return (
    <div className="notice error">
      <strong>{error.isNetwork ? "Cannot reach the API." : "Request failed."}</strong>{" "}
      {error.message}
    </div>
  );
}
