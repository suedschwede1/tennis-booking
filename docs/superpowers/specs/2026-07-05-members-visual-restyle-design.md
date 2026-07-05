# Members module: PatternFly 6 visual restyle

## Context

A design handoff (`Mitgliederverwaltung.dc.html` + README, from `~/Downloads/Mitgliederverwaltung für Verein/design_handoff_mitgliederverwaltung`) specifies a redesigned member-management UI styled as a Nova/PatternFly 6 enterprise app: dark masthead + sidebar, light content area, card/table/KPI-tile components using PF6 global design tokens.

The handoff covers 8 screens, but only 5 exist in the current codebase (Übersicht/Dashboard, Mitglieder, Fiskaljahre, Beitragskategorien, Beiträge/Dues); Funktionen (roles) and Mail are net-new. This is too large for one spec, so it's split into sub-projects:

1. **This spec**: visual restyle of the 5 existing screens — colors, spacing, card/table/badge styling, and the handoff's cosmetic layout patterns. No behavior, data, or route changes.
2. (Later) New Funktionen (roles) screen.
3. (Later) New Mail screen.
4. (Later) Behavior changes the handoff implies but this spec excludes: row-level edit/save/cancel mode (vs. today's per-cell inline autosave), "Ruhend" (dormant) status tri-state, payment-status pills on the member list, Beiträge manual-add inline row, Teilbetrag/Kommentar/Bezahlt-bis columns, fee-category-rate editor UI.

## Current state

The Members module (`app/Modules/Members`) is server-rendered Laravel Blade with Alpine.js for the existing per-cell inline editing, not a React/Nova app. It already has its own small component system in `resources/css/app.css` (`.ui-card`, `.ui-btn`, `.ui-table`, `.ui-kpi`, `.ui-badge`, `.ui-grid-*`, `.ui-field`, etc.) driven by CSS custom properties under `:root` (`--ui-orange`, `--ui-sidebar`, `--ui-bg`, `--ui-border`, etc.). Structurally this system already mirrors PF6 conventions (card = surface + border + radius, KPI tile = label + big number, table = hairline-divider rows, badge = colored pill) — the handoff's `.nv-*` classes in `Mitgliederverwaltung.dc.html` map almost 1:1 onto the existing `.ui-*` classes.

Because the prototype's sandbox couldn't load a live PatternFly 6 bundle, its CSS aliases (`--mv-*`) point at PF6 global token *names* (`--pf-t--global--*`) rather than resolved hex values. This codebase has no PatternFly bundle either, so the restyle hardcodes resolved hex values into the existing `--ui-*` variables, the same pattern already used today.

## Goal

Swap the current club-orange visual identity for the PF6-resolved palette, and adopt the handoff's cosmetic layout patterns, without touching any controller, route, model, or JS interaction logic.

## Token changes

In `resources/css/app.css`, replace the `:root` block:

| Variable | Current | New (PF6-resolved) | PF6 token this represents |
|---|---|---|---|
| `--ui-orange` (primary/brand) | `#bf4316` | `#0066cc` | `--pf-t--global--color--brand--default` |
| `--ui-orange-hover` | `#9e3412` | `#004080` (brand, darker) | — |
| `--ui-orange-soft` | `#fde8e1` | `#e7f1fa` (brand tint) | — |
| `--ui-sidebar` (masthead/sidebar) | `#1b1d21` | `#151515` | `--pf-t--global--background--color--inverse--default` |
| `--ui-bg` (page background) | `#fafafa` | `#f0f0f0` | `--pf-t--global--background--color--secondary--default` |
| `--ui-surface` (card background) | `#ffffff` | `#ffffff` (unchanged) | `--pf-t--global--background--color--primary--default` |
| `--ui-border` (hairline) | `#e0e0e0` | `#d2d2d2` | `--pf-t--global--border--color--default` |
| `--ui-text` | `#151515` | `#151515` (unchanged) | `--pf-t--global--text--color--regular` |
| `--ui-text-secondary` | `#6a6e73` | `#6a6e73` (unchanged) | `--pf-t--global--text--color--subtle` |
| `--ui-text-muted` | `#b8b8b8` | `#b8bbbe` | — |
| danger (`.ui-error`, `.ui-btn-danger`, inline) | `#c9190b` | `#c9190b` (unchanged) | `--pf-t--global--color--status--danger--default` |
| success (`.ui-badge-success`) | current success color | `#3e8635` | `--pf-t--global--color--status--success--default` |
| warning (new, for future Ruhend/Ausstehend use) | — | `#f0ab00` | `--pf-t--global--color--status--warning--default` |
| card radius (`.ui-card`) | `8px` | `6px` | `--pf-t--global--border--radius--medium` |
| button/input radius (`.ui-btn`, `.ui-input`, `.ui-select`) | `6px` | `3px` | `--pf-t--global--border--radius--small` |

Text-on-dark-chrome (`--pf-t--global--text--color--inverse`) stays `#ffffff`, matching current sidebar text color — no change needed there.

Hardcoded hex outside these variables also gets updated to match: the `bg-[#f3f1ec]` page-background utility in `app/Modules/Members/resources/views/layout.blade.php:14` becomes `bg-[#f0f0f0]`, and the inline sidebar/masthead styles in that same file (`background:#1b1d21`, active-item border color `#bf4316`) get updated to the new sidebar/brand hex. Fonts (Red Hat Display/Text) are unchanged — the handoff doesn't specify different fonts and the README treats font family as a token alias, not new content.

**Exact hex values are resolved from general PatternFly 6 knowledge, not fetched from a live token bundle (none was reachable).** Per the handoff's own fidelity note, these should be spot-checked against a live PatternFly 6 app before this ships, but that verification is outside what this implementation can do — flag it for the user to eyeball once deployed.

## Per-screen layout changes

All 5 screens keep their existing routes, controllers, filters, and edit interactions. Only markup/CSS changes:

- **Übersicht** (`dashboard.blade.php`): currently a single `.ui-grid-3`-styled-as-4 KPI block (active count, open dues, category breakdown, age structure all as flat KPI cards). Restructure to the handoff's pattern: a 3-tile KPI row (Aktive Mitglieder, Offene Beiträge [count + € sum], Mitglieder pro Kategorie [category count]) followed by a `220px / 1fr` two-column row — left: "Altersstruktur" card as a hairline-divided list of age bands with counts (same buckets already computed by `DashboardController`); right: "Mitglieder pro Kategorie" card rendering each category as a label + count + a horizontal bar (7px height, rounded, brand-colored fill width proportional to the max category count). No new backend data — `$categoryCounts` and `$ageStructure` already provide everything needed; the bar-width percentage is computed in the view (`count / max(counts) * 100%`).
- **Mitglieder, Beiträge, Fiskaljahre, Beitragskategorien**: no structural changes — same table columns, same filter bar, same per-cell Alpine inline-edit behavior. Only colors/spacing/badge styling change via the token swap above (e.g. status/payment badges pick up the new success/warning/danger hex once those states exist, but no new badge states are introduced now).

## Out of scope (deferred, listed in Context above)

Explicitly not part of this spec: Funktionen screen, Mail screen, row-level edit/save/cancel UX, "Ruhend" status, payment-status column on the member list, Beiträge inline manual-add row, Teilbetrag/Kommentar/Bezahlt-bis columns, fee-category-rate editor. These require new data/behavior and get their own spec + plan later.

## Testing

No backend logic changes, so no new PHP tests are needed. Verification is visual: load each of the 5 screens in a browser (via `php artisan serve` under WSL) and confirm cards/tables/buttons/badges render with the new palette and the Übersicht layout matches the KPI-row + two-column pattern, with no change in filter/edit functionality.
