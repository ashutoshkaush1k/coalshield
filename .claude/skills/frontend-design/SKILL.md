---
name: frontend-design
description: Design language for the Smart Mine Governance dashboards - the "core sample board" direction, risk colour semantics, type scale, spacing, and component conventions. Load before writing or restyling any frontend component, page, or CSS in frontend/src, so new work matches what is already there instead of drifting toward framework defaults.
---

# Frontend design language

This app is judged on a projector, in a room, in about eight minutes. Every rule below exists
because of that. When a rule and a preference disagree, the rule wins.

The direction is a **core sample board**: a field document from a survey, not a SaaS dashboard.
Warm limestone ground, coal ink, solid squared blocks, and mines drawn as drill cores. Tokens live
in `frontend/src/styles/theme.css`. Never hardcode a colour, size, or spacing value in a component.

---

## 1. Risk colour is the most important signal in this app

A viewer should be able to tell how a mine is doing from across the room, before reading a single
word. Everything else on screen is secondary to that.

### The convention is fixed. Do not redefine it.

| Band | Score | Token | Meaning |
|---|---|---|---|
| LOW | 80-100 | `--risk-low` `#4A6B4F` | Compliant |
| MEDIUM | 50-79 | `--risk-medium` `#E0A62E` | Needs attention |
| HIGH | 0-49 | `--risk-high` `#B23A2E` | Urgent |

These mirror `services/compliance/risk.py`. If the bands ever change, they change in both places in
the same commit, and this table changes with them.

### Never encode risk by hue alone

This is the single most important rule in this document.

Green/amber/red is the worst possible palette for red-green colour blindness, which affects roughly
1 in 12 men - a real probability in any judging panel. Projectors also shift and wash out colour
unpredictably. A design that says "red means bad" and nothing else fails silently for part of its
audience.

So **every risk indicator carries at least two signals**: the colour, and the written band label or
the numeric score.

- `RiskMark` renders a colour block plus the words. Use it.
- On the core sample board, the score and band label sit **inside** the filled core. Below roughly a
  quarter depth the core cannot hold its own text, so the readout moves above the fill and switches
  to ink - it never disappears.

Wrong: a bare coloured square, a traffic-light dot, a colour-only table cell.

### Risk colour is reserved

`--risk-*` means risk. Never reuse it for decoration, hover states, or chart series. Alert red in
particular is **HIGH band only** - a breach-count bar is a measurement, not a band, so it uses
`--ochre`. The one other use of red is a genuine error state, which reads correctly from context.

---

## 2. Palette

```
--limestone      #EFE9DD   page ground
--surface        #F7F3EA   panels
--surface-deep   #E4DCCC   header bands, inset areas, hover fills
--surface-deeper #D8CDB8   rules and chart grid

--coal           #1A1712   primary ink
--coal-soft      #4A4038   secondary text
--coal-faint     #7A6E60   metadata, inactive

--ochre          #8B5A2B   labels, subheads, chart series
--ochre-deep     #6D4621   links, medium-band text on light ground
```

Separation comes from **tone**, not from hairlines or shadows: a panel is a lighter block on a
darker ground, with a solid 2px coal edge.

Each risk band also carries an `-on` token - the only text colour that holds contrast against that
fill, since scores are rendered inside coloured cores. Amber takes coal; red and green take
limestone. Do not guess these.

Every colour on screen should be explainable in one sentence. "It looked good" is not a reason.

---

## 3. Typography

Three families, each with one job:

| Family | Token | Use |
|---|---|---|
| Space Grotesk | `--font-display` | Headlines, hero numbers, scores, tab labels, buttons |
| IBM Plex Sans | `--font-body` | Body text, descriptions, labels |
| IBM Plex Mono | `--font-mono` | **Only** genuinely tabular values: mine codes, timestamps, action names |

Mono is not a decorative label style. If it is not a value you could put in a spreadsheet column,
it is not mono.

Fonts are bundled locally via `@fontsource/*`, never a CDN link - the venue network cannot be
assumed, and a dashboard that falls back to Times on stage is a lost demo.

### Scale

`--text-hero` 62 · `--text-display` 38 · `--text-xl` 24 · `--text-lg` 17 · `--text-md` 14.5 ·
`--text-sm` 13 · `--text-xs` 11.5

Seven steps. An eighth means two things differ by a hair, which reads as a mistake rather than a
hierarchy.

### Numerals

Any number compared against another gets `font-variant-numeric: tabular-nums`. Scores, urgency,
counts, deltas, table figures. Without it, digits jitter between refreshes on a live-polling board
and the whole thing looks unstable. Large numbers also take `--tracking-tight`.

### Case

**Never use ALL-CAPS.** Not for labels, not for table headers, not for buttons. Caps read as
template chrome. Labels are distinguished by being small, ochre, and semibold - the `.label` class.

---

## 4. Spacing

A 4px base: `--space-1` through `--space-10`. Use the tokens, not raw pixels.

Related things sit closer than unrelated things. A label and its value get `--space-1`; two
unrelated blocks get `--space-5` or more. Uniform spacing everywhere destroys hierarchy.

Layout uses `.stack`, `.row`, and `.grid` with a modifier. Reach for those before writing a new
flex container.

---

## 5. Components

### Panels

Every content block is a `.panel-block` (or the `Card` component, which renders one): solid 2px
coal edge, a `--surface-deep` header band, flat body. No rounding beyond `--radius`, no shadow, no
hairline dividers.

### Tabs

Each screen answers **one** question. When a page starts answering three, it becomes tabs.

- Tabs sit inline top-right of the masthead, underlined-on-active, never pills or buttons.
- The active underline is `--risk-high` - the one deliberate non-band use of that colour, as a
  wayfinding accent rather than a status.
- Tab state is **local component state, never a route**. Switching a tab must not reload, refetch,
  or re-enter the router. Existing routes keep working: a deep link renders the same shell with
  that tab preselected.
- Panels enter with a 200ms fade and rise. Nothing longer, nothing that moves on every poll.

### Core sample board

Mines as drill cores: a fixed-depth tube per mine, filled to its compliance score, **worst on the
left**. Band boundaries (80, 50) are drawn across the whole board so a viewer can see which cores
fall below them without reading a number. Cores are clickable into the drill-down and lift slightly
on hover.

### Tables

Header row is a `--surface-deep` band with small ochre labels - not uppercase, not a hairline rule.
Rows separate with 2px `--surface-deep`. No zebra striping. Numbers right-aligned and tabular,
identifiers in mono. Hover feedback only on rows that do something.

### Charts

- One series per chart. Overlaying gas, dust and temperature on shared axes is unreadable when
  units differ.
- Threshold lines are dashed `--risk-high` and always labelled with the value.
- Breach markers on exceptions only; compliant points get no dot.
- Horizontal grid only, no chart border, no background fill, no rounded bars.
- `isAnimationActive={false}` - a board that re-animates every 5 seconds looks broken.
- Recharts sets stroke and fill as SVG attributes where `var(--x)` does not resolve, so read tokens
  through `utils/tokens.js` rather than pasting hex into a component.

### Live regions

Anything that polls shows when it last updated, via the masthead's `updatedAt`. Polling belongs in
`usePolling`, never a bespoke `setInterval`: browsers throttle timers to roughly once a minute in a
hidden tab, and `usePolling` refetches on `visibilitychange` so switching to a backgrounded tab
shows current data immediately. The live pulse is `--ochre`, never a risk colour.

---

## 6. Against generic defaults

The failure mode here is not ugliness. It is looking like a template - the judges have seen fifty
Bootstrap dashboards this week. Specifically refuse:

- **Coloured left-border cards.** The status-stripe card is the single most recognisable dashboard
  cliche. Status is carried by the core fill itself.
- **Rounded pill badges.** Risk is a squared colour block plus words.
- **ALL-CAPS labels.** Small, ochre, semibold instead.
- **Middle-dot separated meta strings** (`Angul, Odisha · 36 readings · East region`). Give each
  value a label, or write a sentence.
- **Hairline-rule broadsheet styling.** Structure comes from solid 2px edges and tonal blocks.
- **Gradient washes, glassmorphism, neon glow, soft grey card shadows.**
- **Unstyled browser defaults** and **framework kits** - no Bootstrap, Material, or Tailwind
  utility soup. A `className` with eight utilities is a component that should have a name.
- **Emoji as UI**, icon-only controls, centred body text, marketing-page rhythm.
- **Animation for its own sake.** Hover feedback and the 200ms panel entry, nothing else.

Stated positively: warm flat ground, solid squared blocks, three type families each with one job,
tabular numbers, and colour reserved for meaning.

---

## 7. Two views, one language

The Government and Mine Head views must be visually indistinguishable in style - same panels, type,
spacing, and risk treatment. Only the scope of data and the set of tabs differ.

Share the **presentational components** (`RiskMark`, `CoreSampleBoard`, `BreachTrendChart`,
`AlertList`, `TrendsPanel`) and the **data composition** (`loadMineBundle`), so the two views cannot
drift apart. They no longer share a whole page component, because they answer different questions:
Government gets Overview / Priority Queue / Trends, Mine Head gets Overview / Trends only.

**Mine Head never gets a Priority Queue tab.** Cross-mine ranking is authority-only (PRD 4.1).
Hiding the tab removes the entry point; the server is the boundary - `GET /api/v1/inspections`
returns 403 for that role, and that is what actually enforces it. Never add a UI-only restriction
and call it access control.

---

## 8. Checklist before finishing a component

- Every colour, size, and spacing value comes from a token.
- Risk is shown with colour **and** a label or number.
- Numbers that get compared are tabular.
- Nothing is uppercase. No pills. No left-border status cards. No middle-dot meta strings.
- Mono is used only for values that belong in a spreadsheet column.
- It uses `panel-block` / `.stack` / `.row` / `.grid` rather than bespoke containers.
- Empty, loading, and error states exist - `EmptyState`, `Loader`, `ErrorNotice`.
- A 403 reads as "access restricted", never as a crash.
