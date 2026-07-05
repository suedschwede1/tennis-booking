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

`left_at` stays as the "date departed" record, and drives `status` automatically — **it is the trigger, not `status`**:
- Saving a non-null `left_at` (whether via the list's per-cell editor or the profile form) sets `status = 'ausgetreten'`.
- Clearing `left_at` back to null sets `status = 'aktiv'` (not back to whatever it was before — e.g. a previously-`ruhend` member whose `left_at` gets set then cleared again ends up `aktiv`, matching the user's stated expectation).
- `status = 'ruhend'` is set independently, only via the manual Aktiv/Ruhend toggle described below, and never touches `left_at`.
- Manually toggling to `'aktiv'` while `left_at` is set also clears `left_at` (the two inverse operations — clearing the date, or picking "Aktiv" — are equivalent).

This coupling lives in one place: `Member::setLeftAt(?string $date): void` (called wherever `left_at` is written — the list's per-cell editor and the profile form) and `Member::setStatus(string $status): void` (called by the Aktiv/Ruhend toggle), so the side-effects aren't duplicated across call sites.

**`Member::destroy()` and the "Austragen" button/route are removed entirely** — there is no longer a separate departure action. Departure is just editing `left_at`, exactly like editing `joined_at` already works today.

`Member::scopeActive()` changes from `whereNull('left_at')` to `where('status', 'aktiv')` — this is the one behavior change with a real downstream effect: **ruhend members are now excluded from `MemberDueController::generate()`'s "Beiträge für dieses Fiskaljahr generieren"**, exactly like ausgetreten members already are.

**Explicitly out of scope for this spec:** real or soft deletion of a member record. A separate, already-written spec ([member-anonymization-design.md](2026-07-05-member-anonymization-design.md)) covers the DSGVO retention/anonymization need instead — that becomes its own sub-project's implementation plan, not part of this one.

## Mitglieder list

- **Status filter**: the existing `<select name="status">` (currently Alle/Aktiv/Ausgetreten) gains a third `Ruhend` option, in list order Aktiv/Ruhend/Ausgetreten/Alle anzeigen (matching the handoff's stated order), still defaulting to Aktiv. The controller's status-filter branch changes from checking `left_at` to checking `status` directly (`aktiv`/`ruhend`/`ausgetreten`).
- **Status pill + toggle**: a colored pill (green=Aktiv, warning/orange=Ruhend, grey=Ausgetreten) using the same per-cell pattern as other editable fields in this table. Unlike other cells, its edit control only ever offers **Aktiv/Ruhend** as selectable options — "Ausgetreten" is never directly selectable here, since it's derived from `left_at` (see above), not chosen. Picking a value calls `Member::setStatus()` via the existing `PATCH .../field` endpoint (extended to whitelist `status`, restricted server-side to `aktiv`/`ruhend` values only — attempting to set `ausgetreten` through this endpoint is rejected).
- **New "Austritt am" column**: `left_at`, editable via the same per-cell date-input pattern already used for `joined_at` (click → date input → autosave on blur), extended through the same `PATCH .../field` endpoint (whitelist `left_at`, calling `Member::setLeftAt()`). Empty by default; entering a date is how an admin now records a departure, and clearing it reactivates the member.
- **New "Zahlung" column**: a read-only pill sourced from the member's most recent `MemberDue` (`$member->dues()->latest('fiscal_year_id')->first()`), using `displayStatus()` from the existing `MemberDue` model: Bezahlt=green, Ausstehend=warning, Überfällig=danger, or "–" if the member has no due at all. Not editable from this list — editing a due's status happens on the Beiträge screen, this column is purely informational.

## Mitglied-Formular

- `_form.blade.php`'s "Verein" section gains an `left_at` date field (there isn't one today), next to `joined_at`, going through `Member::setLeftAt()` on save — same trigger as the list's per-cell editor, just via the regular form submit path (`MemberController::update()`), not the field-update endpoint.
- No separate toggle button is added to the form — the Aktiv/Ruhend toggle lives only in the list (see above); there's no second place to change it. This replaces the earlier idea of a form-header toggle button, which was based on a wrong assumption (there's no "Löschen" button in the form to place it next to).

## Out of scope (deferred, per the ordering above)

Member anonymization/deletion (separate existing spec, to be planned next), Funktionen screen, Mail screen, Beitragssatz-per-fiscal-year editor.

## Testing

- Feature test: saving a non-null `left_at` (via the field-update endpoint and via the form) sets `status = 'ausgetreten'`.
- Feature test: clearing `left_at` back to null sets `status = 'aktiv'`.
- Feature test: setting status to `ruhend` via the field-update endpoint leaves `left_at` untouched.
- Feature test: setting status to `aktiv` via the field-update endpoint while `left_at` is set also clears `left_at`.
- Feature test: the field-update endpoint rejects `status = 'ausgetreten'` (not a valid manual value for that field).
- Feature test: `MemberDueController::generate()` skips `ruhend` members, same as `ausgetreten` ones (extends the existing "generating dues creates one per active member" test with a ruhend-member case).
- Feature test: the Mitglieder list's status filter returns the correct set for `ruhend`.
- Feature test: the payment-status pill on the list reflects the latest due's `displayStatus()`, and shows "–" for a member with no dues.
- Feature test: the old `members.destroy` route no longer exists / the "Austragen" button is gone from the list.
