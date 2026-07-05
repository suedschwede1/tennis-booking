# Beiträge: Status, Teilbetrag, Kommentar, Bezahlt-bis, and inline manual entry

## Context

This is the second sub-project from the "Mitgliederverwaltung" design handoff, following [members-visual-restyle-design.md](2026-07-05-members-visual-restyle-design.md). The handoff's Beiträge (dues) screen adds several columns and behaviors this codebase doesn't have yet: a directly editable Status pill, a Teilbetrag (partial amount) column, a Kommentar column, a "Bezahlt bis" fiscal-year field that marks advance payment, and an inline "Beitrag manuell anlegen" row replacing the separate create page.

Decomposition of the original handoff (see the visual-restyle spec's Context section), in agreed order:
1. ✅ Visual restyle (done)
2. **This spec:** Beiträge status/columns/manual-entry
3. (Next) Ruhend member status + payment-status pill on the member list
4. (Later) Funktionen screen
5. (Later) Mail screen
6. (Later) Beitragssatz-per-fiscal-year editor

**Permanently dropped, not deferred:** the handoff's row-level edit/save/cancel mode. This codebase keeps its existing per-cell inline-autosave editing pattern (click a cell → input/select appears → saves on blur/change, matching [members/index.blade.php](../../../app/Modules/Members/resources/views/members/index.blade.php)) for both the Mitglieder and Beiträge tables. Every new editable field in this spec uses that same pattern, not a row-level edit toggle.

## Current state

`MemberDue` ([Model](../../../app/Modules/Members/Models/MemberDue.php), [migration](../../../app/Modules/Members/Database/Migrations/2026_07_03_090005_create_member_dues_table.php)) has `member_id`, `fiscal_year_id`, `fee_category_id`, `amount`. Paid/unpaid is currently *derived*, never stored: `isPaid()` checks whether any `Payment` is attached via the `payment_dues` pivot. `PaymentController::store()` ([PaymentController.php:53-67](../../../app/Modules/Members/Http/Controllers/PaymentController.php)) deliberately requires a payment to cover the exact sum of the selected dues — partial payments are rejected by design, with a comment explaining why: a partial payment would otherwise mark dues "paid" and permanently block the remainder. **This spec does not change that exact-match payment path.** It adds a separate, manually-controlled status field alongside it.

`dues/create.blade.php` is a full-page form (member select, fiscal year, fee category, amount) with JS that auto-fills fee category when a member is selected. This spec replaces it with an inline row on the index page, reusing the same auto-fill behavior.

## Data model changes

Migration adding to `member_dues`:

| Column | Type | Default | Notes |
|---|---|---|---|
| `status` | string enum: `ausstehend`, `bezahlt`, `ueberfaellig` | `ausstehend` | The single manual source of truth for what's displayed (see Status logic below). |
| `teilbetrag` | decimal(8,2) nullable | `null` | Free-standing informational field — not read by any payment logic. |
| `kommentar` | text nullable | `null` | Free text. |
| `bezahlt_bis_fiscal_year_id` | nullable FK → `fiscal_years.id` | `null` | Last fiscal year this due's advance payment covers. |
| `faellig_am` | date nullable | fiscal year's `starts_on` at creation time | Editable afterward; drives automatic overdue display. |

`MemberDue`'s `$fillable` gains all five; add a `bezahltBisFiscalYear(): BelongsTo` relation.

## Status logic

- `status` is the manual field the admin edits directly (dropdown: Ausstehend/Bezahlt/Überfällig) via the same per-cell autosave pattern as the rest of the table.
- Creating a due (via `generate()` or the new manual-entry row) always sets `status = 'ausstehend'` and `faellig_am` = the fiscal year's `starts_on`.
- `PaymentController::store()` gains one side effect: after successfully attaching a payment to the selected dues (inside the existing `DB::transaction`), it also sets `status = 'bezahlt'` on each of those `MemberDue` rows. This keeps the exact-match payment path and the manual field in sync without changing the payment path's validation.
- **Display-time overdue override:** wherever the status is rendered or filtered, use a `MemberDue::displayStatus(): string` accessor rather than the raw `status` column: if `status === 'ausstehend'` and `faellig_am` is set and in the past, it returns `'ueberfaellig'`; otherwise it returns `status` unchanged. This is computed, not persisted — editing the manual `status` field always edits the real stored value, and the automatic downgrade only affects what's *shown*.

## "Bezahlt bis" (advance payment)

A new editable cell on each due row: a fiscal-year `<select>` (options limited to fiscal years at or after the row's own `fiscal_year_id`, ordered by `starts_on`), defaulting to empty. Choosing a year `Y`:

1. Updates the current row: `bezahlt_bis_fiscal_year_id = Y`, `status = 'bezahlt'`.
2. For every `FiscalYear` that already exists strictly between the current row's fiscal year and `Y` (by `starts_on` ordering), find-or-create a `MemberDue` for the same `member_id` + `fee_category_id`, copying `amount` from the current row, and set that row's `status = 'bezahlt'` and `bezahlt_bis_fiscal_year_id = Y` too (creating it with `faellig_am` = that fiscal year's `starts_on` if newly created).
3. If a `MemberDue` already exists for one of those intermediate years, only its `status` and `bezahlt_bis_fiscal_year_id` are updated — its existing `amount`/`fee_category_id` are left alone.
4. This entire operation runs in one `DB::transaction`.
5. No new `FiscalYear` rows are ever created by this — the select only offers years that already exist in `fiscal_years`.

New controller method `MemberDueController::markPaidThrough(Request $request, MemberDue $due)` (route: `PATCH members/dues/{due}/paid-through`, validates `fiscal_year_id` exists and is at-or-after the due's own fiscal year) implements this and returns JSON (matching the `updateField` JSON contract below) so the same Alpine per-cell pattern can call it.

## Per-cell inline editing (Status, Teilbetrag, Kommentar)

New `MemberDueController::updateField(Request $request, MemberDue $due): JsonResponse` mirroring [`MemberController::updateField`](../../../app/Modules/Members/Http/Controllers/MemberController.php:113) exactly: a `$fieldRules` map for `status` (`Rule::in(['ausstehend', 'bezahlt', 'ueberfaellig'])`), `teilbetrag` (`nullable, numeric, decimal:0,2, min:0, max:999999.99`), and `kommentar` (`nullable, string, max:1000`); validates `field` is one of those three keys; updates the one field; returns `{"value": ...}` with the same per-field display formatting pattern (e.g. teilbetrag formatted as `"12,50 €"` or the string `"–"` when null, matching the handoff's "shown as a warning-colored value or '–'").

New route: `PATCH members/dues/{due}/field` → `members.dues.update-field`.

The Blade table cells for Status, Teilbetrag, and Kommentar follow the exact Alpine structure already used in `members/index.blade.php` (`x-data` with `editing`/`error`/`justSaved`, `@click.outside`, `window.saveMemberField`-style helper — here a sibling `window.saveDueField` calling the new route). Teilbetrag renders in a warning color (existing `.ui-badge-warning`-equivalent CSS color, reusing the `#f0ab00` PatternFly warning token already in `resources/css/app.css` from the visual-restyle work) when non-null, `text-secondary` "–" otherwise. Status renders as a colored pill using the existing `.ui-badge-*` classes: success for Bezahlt, warning for Ausstehend, danger for Überfällig — introducing `.ui-badge-warning` (color `#f0ab00`, matching the tokens already in the file) since only `-success`/`-danger`/`-info` exist today.

## Inline manual-entry row

The "Beitrag manuell anlegen" header link becomes an Alpine-toggled `<tr>` appended to the table (hidden by default), reusing the member-list/fee-category data already passed to the index view (extend `MemberDueController::index()` to also pass `$members` and `$feeCategories`, the same querysets `create()` currently builds). Fields: Name (a `<select>` with `data-fee-category` attributes, same auto-fill-on-change JS pattern as the current `dues/create.blade.php`, adapted inline), Beitragskategorie, Betrag, Kommentar, then "Hinzufügen" submits a small form to the existing `members.dues.store` route (unchanged validation, since `status`/`faellig_am` get their creation defaults server-side regardless of caller). `dues/create.blade.php`, its route (`members/dues/create`), and the `create()` controller method are deleted; `index()` gains the `$members`/`$feeCategories` view data `create()` used to provide.

## Testing

- Feature test: creating a due via `generate()` or `store()` sets `status = 'ausstehend'` and `faellig_am` to the fiscal year's `starts_on`.
- Feature test: `PATCH .../field` updates `status`, `teilbetrag`, `kommentar` individually and rejects invalid values/fields (mirroring the existing `MemberController::updateField` test coverage).
- Feature test: `displayStatus()` returns `'ueberfaellig'` when `status='ausstehend'` and `faellig_am` is in the past, and returns the raw `status` otherwise (including when `status='bezahlt'` and `faellig_am` is in the past).
- Feature test: `PaymentController::store()` sets `status='bezahlt'` on the covered dues after a successful exact-match payment.
- Feature test: `markPaidThrough` on a row two fiscal years before `Y` creates the two intermediate `MemberDue` rows (correct member/category/amount/status) and updates all three rows' `bezahlt_bis_fiscal_year_id`; running it again when an intermediate row already exists updates that row's status instead of creating a duplicate.
- Feature test: the inline manual-entry submission still creates a due exactly as `store()` did before (existing test coverage for `store()` should keep passing unchanged; add one asserting the new defaults are applied).
- Remove/replace any existing test coverage for the deleted `dues/create` page route with equivalent coverage of the inline row's underlying `store()` call (already covered) — no separate "renders the create page" test should remain.
