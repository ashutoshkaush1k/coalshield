// Phones: every table row shown as a stacked card (styles/index.css, "phones: tables as cards").
//
// This gives each body cell the name of its column (data-label) so a card can read "label: value",
// and marks rows with more than four cells so the card shows the first four and a "More" line.
// Attributes only - React's own DOM is never restructured. On a phone, a tap on such a card (not on
// a control inside it) expands it to every value; rows that open a drawer say "Details" instead,
// because the drawer shows the whole record. Tables marked .table-scroll (wide numeric matrices)
// are left as tables that scroll inside their card with the first column pinned.
import i18n from "../i18n";

const PHONE = "(max-width: 767px)";

function label(table) {
  if (table.classList.contains("table-scroll")) return;
  const head = table.tHead?.rows[table.tHead.rows.length - 1];
  if (!head) return;
  const names = [];
  for (const th of head.cells) for (let i = 0; i < (th.colSpan || 1); i++) names.push(th.textContent.trim());
  if (!table.hasAttribute("data-cards")) table.setAttribute("data-cards", "");
  const more = i18n.t("common.more");
  const details = i18n.t("common.details");
  for (const body of table.tBodies) {
    for (const row of body.rows) {
      let i = 0;
      for (const td of row.cells) {
        const name = names[i] ?? "";
        if (td.getAttribute("data-label") !== name) td.setAttribute("data-label", name);
        i += td.colSpan || 1;
      }
      const opens = row.classList.contains("clickable") || row.style.cursor === "pointer";
      const hint = opens ? details : row.cells.length > 4 ? more : null;
      if (hint && row.getAttribute("data-more") !== hint) row.setAttribute("data-more", hint);
      if (opens && !row.hasAttribute("data-opens")) row.setAttribute("data-opens", "");
    }
  }
}

export function startTableCards() {
  let pending = false;
  const run = () => { pending = false; document.querySelectorAll("table").forEach(label); };
  new MutationObserver(() => {
    if (!pending) { pending = true; requestAnimationFrame(run); }
  }).observe(document.body, { childList: true, subtree: true, characterData: true });
  i18n.on("languageChanged", () => requestAnimationFrame(run));
  run();
  document.addEventListener("click", (e) => {
    if (!window.matchMedia(PHONE).matches) return;
    const row = e.target.closest("tr[data-more]");
    if (!row || row.hasAttribute("data-opens") || e.target.closest("a, button, input, select, textarea, summary, label")) return;
    row.toggleAttribute("data-expanded");
  });
}
