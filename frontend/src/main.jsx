// React entry point: mounts App inside AuthProvider and the router.
import React from "react";
import { createRoot } from "react-dom/client";
import App from "./App";
import "./i18n";
import "./styles/theme.css";
import "./styles/index.css";
import { startTableCards } from "./utils/tableCards";

// Phones: table rows as stacked cards (utils/tableCards.js).
startTableCards();

createRoot(document.getElementById("root")).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
);

// Phase 7B: the service worker (built by vite.config.js) keeps the app shell, translations and
// fonts on the device, so the field app opens with no network. Development builds have none.
// VITE_SW_SCOPE (online: /field) limits it to the field app; unset, the whole site as before.
const SW_SCOPE = import.meta.env.VITE_SW_SCOPE || "/";
if (import.meta.env.PROD && "serviceWorker" in navigator && window.location.pathname.startsWith(SW_SCOPE)) {
  window.addEventListener("load", () => navigator.serviceWorker.register("/sw.js", { scope: SW_SCOPE }).catch(() => {}));
}
