// Top-level shell; renders the role-based route tree.
import { BrowserRouter } from "react-router-dom";
import { AuthProvider } from "./auth/AuthContext";
import { ToastHost } from "./components/overlay/ToastHost";
import { AppRoutes } from "./routes";

export default function App() {
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
