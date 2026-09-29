// The photo behind the login and public grievance pages: a full-window cover, fixed to the viewport,
// under a navy gradient (darker behind the form) so every text on it meets WCAG AA. Bundled locally
// (frontend/scripts/make_login_bg.py makes the AVIF, WebP and JPEG versions); the navy shows at once
// and the photo fades in when it has loaded (no fade with reduced motion; styles/index.css).
import { useEffect, useRef, useState } from "react";
import a640 from "../../assets/login-bg/login-bg-640.avif";
import a960 from "../../assets/login-bg/login-bg-960.avif";
import a1093 from "../../assets/login-bg/login-bg-1093.avif";
import w640 from "../../assets/login-bg/login-bg-640.webp";
import w960 from "../../assets/login-bg/login-bg-960.webp";
import w1093 from "../../assets/login-bg/login-bg-1093.webp";
import j640 from "../../assets/login-bg/login-bg-640.jpg";
import j960 from "../../assets/login-bg/login-bg-960.jpg";
import j1093 from "../../assets/login-bg/login-bg-1093.jpg";

const set = (a, b, c) => `${a} 640w, ${b} 960w, ${c} 1093w`;

export function BrandBackground() {
  const [loaded, setLoaded] = useState(false);
  const img = useRef(null);
  // A cached image can finish before React attaches onLoad.
  useEffect(() => { if (img.current?.complete && img.current.naturalWidth) setLoaded(true); }, []);
  return (
    <div className="brand-bg" aria-hidden="true">
      <picture>
        <source type="image/avif" srcSet={set(a640, a960, a1093)} sizes="100vw" />
        <source type="image/webp" srcSet={set(w640, w960, w1093)} sizes="100vw" />
        <img ref={img} src={j1093} srcSet={set(j640, j960, j1093)} sizes="100vw" alt="" decoding="async"
             className={loaded ? "is-loaded" : ""} onLoad={() => setLoaded(true)} />
      </picture>
      <div className="brand-bg-shade" />
    </div>
  );
}
