// Top bar: hamburger, logo and app name on the first line; the page title on the left and its tabs
// top-right below (on their own line when they do not fit beside it). `barIds` picks the tabs shown
// on the bar; the others are published to the navigation drawer (Nav.jsx).
import { useEffect, useRef } from "react";
import { Tabs } from "../common/Tabs";
import { NavToggle, useNav } from "./Nav";
import { useMastheadHeight } from "./useMastheadHeight";

export function Masthead({ title, subtitle, tabs, barIds, active, onTabChange }) {
  const ref = useRef(null);
  useMastheadHeight(ref);
  const nav = useNav();
  const onBar = (tab) => !barIds || barIds.includes(tab.id);
  const barTabs = tabs?.filter(onBar);
  const drawerTabs = tabs?.filter((tab) => !onBar(tab)) ?? [];

  // Publish the drawer's tabs. Keyed on ids, labels (language) and the active tab, not on the
  // array itself, which is new on every render; the page's handler is read through a ref.
  const change = useRef(onTabChange);
  change.current = onTabChange;
  const key = drawerTabs.map((tab) => `${tab.id}:${tab.label}`).join("|");
  const setPageTabs = nav?.setPageTabs;
  useEffect(() => {
    if (!setPageTabs) return undefined;
    setPageTabs(key ? { tabs: drawerTabs, active, onChange: (id) => change.current?.(id) } : null);
    return () => setPageTabs(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [setPageTabs, key, active]);

  return (
    <header className="masthead" ref={ref}>
      <NavToggle />
      <div className="masthead-row">
        <div className="masthead-title">
          <h1>{title}</h1>
          {subtitle && <div className="sub">{subtitle}</div>}
        </div>

        <div className="spacer" />

        {barTabs?.length > 0 && <Tabs tabs={barTabs} active={active} onChange={onTabChange} />}
      </div>
    </header>
  );
}
