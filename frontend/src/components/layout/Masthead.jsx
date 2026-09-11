// Page title on the left, tabs inline top-right.
import { Tabs } from "../common/Tabs";

export function Masthead({ title, subtitle, tabs, active, onTabChange }) {
  return (
    <header className="masthead">
      <div className="masthead-title">
        <h1>{title}</h1>
        {subtitle && <div className="sub">{subtitle}</div>}
      </div>

      <div className="spacer" />

      {tabs && <Tabs tabs={tabs} active={active} onChange={onTabChange} />}
    </header>
  );
}
