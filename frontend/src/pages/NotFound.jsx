// 404 fallback.
import { Link } from "react-router-dom";

export default function NotFound() {
  return (
    <div className="content">
      <h1>Page not found</h1>
      <p className="muted">That screen does not exist. <Link to="/">Go back</Link>.</p>
    </div>
  );
}
