// A password field with a show / hide toggle inside its right edge (dashboard login, field app
// login and the field app's re-login prompt). The input keeps its id, name and autocomplete, so
// labels and browser autofill work as before. The password is hidden again when the form is
// submitted, when the page is hidden or left, and on reload (the state is not kept).
import { useEffect, useRef, useState } from "react";
import { Eye, EyeOff } from "lucide-react";
import { useT } from "../../i18n/t";

export function PasswordInput({ id, className = "", ...props }) {
  const t = useT();
  const [visible, setVisible] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    const hide = () => setVisible(false);
    const onVisibility = () => { if (document.visibilityState === "hidden") hide(); };
    const form = ref.current?.form;
    form?.addEventListener("submit", hide);
    window.addEventListener("pagehide", hide);
    document.addEventListener("visibilitychange", onVisibility);
    return () => {
      form?.removeEventListener("submit", hide);
      window.removeEventListener("pagehide", hide);
      document.removeEventListener("visibilitychange", onVisibility);
    };
  }, []);

  const label = visible ? t("login.hidePassword") : t("login.showPassword");
  return (
    <div className={`password-field ${className}`}>
      <input ref={ref} id={id} type={visible ? "text" : "password"} autoCapitalize="off" autoCorrect="off" spellCheck={false} {...props} />
      <button type="button" className="password-toggle" aria-label={label} title={label} aria-controls={id}
              aria-pressed={visible} onClick={() => setVisible((v) => !v)}>
        {visible ? <EyeOff size={18} aria-hidden="true" /> : <Eye size={18} aria-hidden="true" />}
      </button>
    </div>
  );
}
