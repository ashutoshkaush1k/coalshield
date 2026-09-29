// The photo behind the login and public grievance pages: a full-window cover, fixed to the viewport,
// under a navy gradient (darker behind the form) so every text on it meets WCAG AA. Bundled locally
// (frontend/scripts/make_login_bg.py makes the AVIF, WebP and JPEG versions and sources.js); the navy shows at once
// and the photo fades in when it has loaded (no fade with reduced motion; styles/index.css).
import { useEffect, useRef, useState } from "react";
import { avif, fallback, jpeg, webp } from "../../assets/login-bg/sources";

export function BrandBackground() {
  const [loaded, setLoaded] = useState(false);
  const img = useRef(null);
  // A cached image can finish before React attaches onLoad.
  useEffect(() => { if (img.current?.complete && img.current.naturalWidth) setLoaded(true); }, []);
  return (
    <div className="brand-bg" aria-hidden="true">
      <picture>
        <source type="image/avif" srcSet={avif} sizes="100vw" />
        <source type="image/webp" srcSet={webp} sizes="100vw" />
        <img ref={img} src={fallback} srcSet={jpeg} sizes="100vw" alt="" decoding="async"
             className={loaded ? "is-loaded" : ""} onLoad={() => setLoaded(true)} />
      </picture>
      <div className="brand-bg-shade" />
    </div>
  );
}
