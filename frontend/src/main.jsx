// React entry point: mounts App inside AuthProvider and the router.
import React from "react";
import { createRoot } from "react-dom/client";
import App from "./App";
import "./i18n";
import "./styles/theme.css";
import "./styles/index.css";

createRoot(document.getElementById("root")).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
);

// Phase 7B: the service worker (built by vite.config.js) keeps the app shell, translations and
// fonts on the device, so the field app opens with no network. Development builds have none.
if (import.meta.env.PROD && "serviceWorker" in navigator) {
  window.addEventListener("load", () => navigator.serviceWorker.register("/sw.js").catch(() => {}));
}
