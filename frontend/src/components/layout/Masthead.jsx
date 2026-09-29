// Page title on the left, tabs inline top-right (on their own line when they do not fit beside it).
import { useRef } from "react";
import { Tabs } from "../common/Tabs";
import { useMastheadHeight } from "./useMastheadHeight";

export function Masthead({ title, subtitle, tabs, active, onTabChange }) {
  const ref = useRef(null);
  useMastheadHeight(ref);
  return (
    <header className="masthead" ref={ref}>
      <div className="masthead-title">
        <h1>{title}</h1>
        {subtitle && <div className="sub">{subtitle}</div>}
      </div>

      <div className="spacer" />

      {tabs && <Tabs tabs={tabs} active={active} onChange={onTabChange} />}
    </header>
  );
}
