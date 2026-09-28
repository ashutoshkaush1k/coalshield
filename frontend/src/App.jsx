// Top-level shell; renders the role-based route tree.
import { useTranslation } from "react-i18next";
import { BrowserRouter } from "react-router-dom";
import { AuthProvider } from "./auth/AuthContext";
import { ToastHost } from "./components/overlay/ToastHost";
import { AppRoutes } from "./routes";

export default function App() {
  // Subscribed here, at the root: a language change re-renders the whole tree, so text from the
  // plain t() helper and Intl formatting switch at once, not at the next poll.
  useTranslation();
  return (
    <BrowserRouter>
      <AuthProvider>
        <ToastHost>
          <AppRoutes />
        </ToastHost>
      </AuthProvider>
    </BrowserRouter>
  );
}
