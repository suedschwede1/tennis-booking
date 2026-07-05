# Mitglieder: Ruhend-Status und Zahlungsstatus-Pille

## Context

Third sub-project from the Mitgliederverwaltung handoff decomposition (see [members-visual-restyle-design.md](2026-07-05-members-visual-restyle-design.md) and [dues-status-and-manual-entry-design.md](2026-07-05-dues-status-and-manual-entry-design.md)), in the order: visual restyle → Beiträge status/columns/manual-entry (both done) → **this spec** → Funktionen screen → Mail screen → Beitragssatz-Editor.

The handoff's Mitglieder list shows a tri-state Status pill (Aktiv/Ruhend/Ausgetreten) and a payment-status pill sourced from the member's latest Beitrag entry. Neither exists today: the codebase only derives a binary active/departed state from `left_at` being null, and the member list has no payment-status column at all.

## Current state

`Member` ([Model](../../../app/Modules/Members/Models/Member.php)) has a `left_at` nullable date and a `scopeActive()` that filters `whereNull('left_at')`. [`MemberController::index()`](../../../app/Modules/Members/Http/Controllers/MemberController.php:24-25) filters `status=departed` as `whereNotNull('left_at')`; there's no third state. [`MemberController::destroy()`](../../../app/Modules/Members/Http/Controllers/MemberController.php:168-175) ("Austragen") sets `left_at` to today if not already set. `Member::active()` is also used by `MemberDueController::generate()` to decide who gets a new due each fiscal year — this is the one place `scopeActive()`'s meaning has a real downstream effect.

The previous sub-project already added `MemberDue::displayStatus()` (auto-derives "ueberfaellig" from a past due date) and a `MemberDue::STATUSES` constant — this spec's payment-status pill reuses that directly rather than reimplementing status logic.

## Data model changes

Migration adding to `members`:

| Column | Type | Default | Notes |
|---|---|---|---|
| `status` | string enum: `aktiv`, `ruhend`, `ausgetreten` | `aktiv` | Single source of truth for the member's state. |

`left_at` stays as the "date departed" record, but its meaning becomes coupled to `status` rather than being independently authoritative:
- Setting `status = 'ausgetreten'` also sets `left_at = left_at ?? now()->toDateString()` (same "don't overwrite an existing departure date" behavior `destroy()` already has).
- Setting `status` to `'aktiv'` (from either `ruhend` or `ausgetreten`) also clears `left_at` to `null` — re-activating a member means they're no longer "departed as of" any date.
- Setting `status = 'ruhend'` does not touch `left_at`.

This coupling lives in one place: a new `Member::setStatus(string $status): void` model method that both controllers (`destroy()` and the new toggle action) call, rather than duplicating the `left_at` side-effect logic in two controllers.

`Member::scopeActive()` changes from `whereNull('left_at')` to `where('status', 'aktiv')` — this is the one behavior change with a real downstream effect: **ruhend members are now excluded from `MemberDueController::generate()`'s "Beiträge für dieses Fiskaljahr generieren"**, exactly like ausgetreten members already are.

## Mitglieder list

- **Status filter**: the existing `<select name="status">` (currently Alle/Aktiv/Ausgetreten) gains a third `Ruhend` option, in list order Aktiv/Ruhend/Ausgetreten/Alle anzeigen (matching the handoff's stated order), still defaulting to Aktiv. The controller's status-filter branch changes from checking `left_at` to checking `status` directly (`aktiv`/`ruhend`/`ausgetreten`).
- **Status pill**: replaces the current plain-text status handling with a colored pill (green=Aktiv, warning/orange=Ruhend, grey=Ausgetreten), editable inline via the same per-cell dropdown pattern already used for `fee_category_id` in this table (click pill → select → autosave on change) — consistent with this codebase's established convention of editing every mutable field in-place in the list, not through a separate action button. Selecting `Ausgetreten` or `Aktiv` in that dropdown goes through `Member::setStatus()` (via the existing `PATCH .../field` endpoint, extended to allow `status` as a whitelisted field), so the `left_at` coupling above applies uniformly whether the change comes from the list or the form.
- **New "Zahlung" column**: a read-only pill sourced from the member's most recent `MemberDue` (`$member->dues()->latest('fiscal_year_id')->first()`), using `displayStatus()` from the existing `MemberDue` model: Bezahlt=green, Ausstehend=warning, Überfällig=danger, or "–" if the member has no due at all. Not editable from this list — editing a due's status happens on the Beiträge screen, this column is purely informational.

## Mitglied-Formular

Header gains a "Ruhend stellen" / "Aktiv setzen" toggle button (label depends on current status — if `ruhend`, show "Aktiv setzen"; if `aktiv` or `ausgetreten`, show "Ruhend stellen"), positioned next to the existing "Löschen" button, calling `Member::setStatus()` with the appropriate target value. This is the only place the toggle appears — consistent with the existing convention that destructive/state-changing actions like "Löschen" live on the profile screen, not the list row.

## Out of scope (deferred, per the ordering above)

Funktionen screen, Mail screen, Beitragssatz-per-fiscal-year editor.

## Testing

- Feature test: setting a member's status to `ausgetreten` via the field-update endpoint sets `left_at` (if not already set); setting it back to `aktiv` clears `left_at`.
- Feature test: setting status to `ruhend` leaves `left_at` untouched.
- Feature test: `MemberDueController::generate()` skips `ruhend` members, same as `ausgetreten` ones (extends the existing "generating dues creates one per active member" test with a ruhend-member case).
- Feature test: the Mitglieder list's status filter returns the correct set for `ruhend`.
- Feature test: the payment-status pill on the list reflects the latest due's `displayStatus()`, and shows "–" for a member with no dues.
- Feature test: the form's toggle button flips status and label correctly in both directions.
