// Generic row-detail drawer for violations and audit entries.
import { Drawer } from "../overlay/Overlay";

export function DetailDrawer({ open, onClose, title, subtitle, fields, children }) {
  return (
    <Drawer open={open} onClose={onClose} title={title} subtitle={subtitle}>
      <div className="stack tight">
        {fields?.filter((f) => f.value !== null && f.value !== undefined && f.value !== "").map((f) => (
          <div key={f.label} className="detail-field">
            <span className="label">{f.label}</span>
            <div className={`detail-value${f.mono ? " mono" : ""}`}>{f.value}</div>
          </div>
        ))}
        {children}
      </div>
    </Drawer>
  );
}
