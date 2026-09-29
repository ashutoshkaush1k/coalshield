// The sticky masthead's height, kept in --masthead-h on the page. styles/index.css turns it into
// scroll-padding, so following a link to a card (#governance-risk) or scrollIntoView stops with the
// card's title below the header instead of under it - whatever the header's height at this width
// and in this language (the tabs may wrap onto a second or third line).
import { useLayoutEffect } from "react";

export function useMastheadHeight(ref) {
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el || typeof ResizeObserver === "undefined") return undefined;
    const root = document.documentElement;
    const measure = () => root.style.setProperty("--masthead-h", `${Math.ceil(el.getBoundingClientRect().height)}px`);
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(el);
    return () => observer.disconnect();
  }, [ref]);
}
