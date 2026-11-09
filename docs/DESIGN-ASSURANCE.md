# Design assurance

`docs/DESIGN.md` defines the target. Enforcement has three independent layers:
source checks, rendered regression tests, and a visual/accessibility review. A passing
source check does not prove layout, contrast, sensible copy, or an accurate mockup match.

## Migration status

| Area | Status | Evidence |
|---|---|---|
| `components/ui/*`, `design/preview.blade.php` | Target design foundation | Shared controls, strict source checks, desktop/phone/dialog references |
| `resources/css/design-tokens.css` | Canonical light token values | A test compares them to the color table in DESIGN.md |
| Calendar, booking dialog, authentication, account, admin, Members | Legacy / migration pending | Existing source findings are inventoried; visual references preserve current behavior |
| Dark mode | Planned | No dark theme is enabled |

Known legacy issue visible in the initial phone reference: the admin dashboard's fixed
sidebar leaves too little room for its content at 390px. This is migration debt, not a
target-design acceptance. Correct it when migrating the admin layout and explicitly
review the replacement reference; the new component preview itself has an enforced
no-horizontal-overflow check.

When migrating a page, add its repository-relative path to `ui-design.migrated` in
`config/ui-design.php`, remove its legacy styles, run `lint:ui --prune-baseline`, and
review its screenshots against the target mockup. Never label a legacy screenshot as
target-design approval. Move a status row to migrated only with that evidence.

## Daily checks

On Windows run PHP and Composer through WSL, from the project directory:

```bash
wsl composer check:design
npm run build
wsl php artisan view:cache
wsl php artisan view:clear
```

`check:design` checks Blade (including both module conventions), application CSS, and
the enforcement tests. It is also included in `composer lint`. The GitHub workflow
`Design checks` runs for pull requests and main/master pushes. After pushing this
workflow, configure its `Design contract` and `Visual references` jobs as required
checks in the repository branch rules; a local file cannot enable server-side rules.

The linter rejects literal colors outside the token sheet, stock Tailwind palette
colors, retired typography, undersized explicit controls/type, inline styling and
unsupported gradients/motion. Email templates retain their separate inline-CSS contract.
These are source heuristics, not a CSS cascade or DOM/accessibility validator.
The existing pre-commit hook also runs the source check for staged UI paths (enable
with `git config core.hooksPath .githooks`). It checks the working tree for fast local
feedback; CI checks the actual commit, so partial staging cannot bypass the remote check.

### Legacy inventory

`ui-design-baseline.json` records existing source locations, rules, normalized source
and occurrence counts. Line shifts alone do not invalidate an exception. New source,
additional occurrences and any violation in a migrated path fail with a nonzero exit.

- `php artisan lint:ui --summary`: report blocking and total debt counts.
- `php artisan lint:ui --strict`: inspect all debt as errors.
- `php artisan lint:ui --rule=CLR`: inspect a rule category.
- `php artisan lint:ui --prune-baseline`: remove fixed debt; never accepts new findings.
- `--write-baseline` is only for initial setup and refuses to overwrite an inventory.

Baseline additions are code-review decisions and require an explanation. Never delete
and recreate the file to turn a failing change green. A removed old occurrence must be
pruned so its allowance cannot be reused later.

On 2026-11-09, CLR-03 added CSS color-function detection (RGB/HSL/OKLCH and related
literal forms). Its initial inventory adds only 63 occurrences in unchanged legacy
sources, each checked against `git show HEAD:<path>` before acceptance. No migrated
source was exempted and no existing allowance was increased. Obsolete fragment-ID
false positives were pruned separately. New color-function occurrences still fail.

## Shared controls

New pages use `<x-ui.button>`, `<x-ui.field>`, `<x-ui.badge>`, `<x-ui.card>` and
`<x-ui.dialog>` inside a `.tc-design` container. CSS uses semantic `--color-*` tokens.
Body/display fonts are scoped `--font-tc-body` / `--font-tc-display` while old pages
still use their legacy font names; Barlow is self-hosted under `resources/fonts/` with
its OFL licenses. No global font migration is implied by adding the component kit.

```blade
<x-ui.field name="partner" :label="__('booking.design.partner')" />
<x-ui.button type="submit">{{ __('booking.design.book') }}</x-ui.button>
```

The local `/__design` page demonstrates normal/error/disabled/status states and a
native modal dialog (keyboard focus stays inside while open; Escape closes it).
Production and staging environments return 404. Components supply reusable defaults;
page authors still choose appropriate labels, actions, content order and error handling.

## Screenshot review

See `tests/Browser/README.md` for setup and commands. The reference set covers the
calendar, booking dialog, login, account and admin at defined widths, plus the new
component kit on desktop/phone and its phone dialog. These are viewport screenshots;
also inspect below the fold when changing long forms/tables.

The original July 2026 calendar fixture has been moved to July 2099 so free slots stay
bookable. Only the admin dashboard's current-date label is replaced with fixed text.
Reference tests set explicit CSS viewport dimensions listed with the PNGs; they do
not depend on the operating system's browser-window decoration. Confined Chromium
may need `DUSK_CHROME_USER_DATA_DIR` pointing to a test-only shared profile directory
so ChromeDriver can read its debugging-port file outside the browser's private `/tmp`.
Match fonts, locale, browser version and OS before diagnosing pixel differences. Missing
references or missing PHP gd fail the suite; they never count as a skipped success.

To intentionally create/update references, run with `UPDATE_DUSK_SNAPSHOTS=1` locally,
inspect every resulting PNG and commit the relevant references with the UI change.
CI refuses snapshot replacement. Baselines are never automatically accepted after a
failure; actual screenshots and browser logs are uploaded for review.

## Acceptance checklist for each changed page

- Compare to the target mockup at phone and desktop widths; check long/empty content.
- Check normal, disabled, error, success and empty states relevant to the change.
- Verify Barlow, token colors, spacing/radii, court aliases and a single primary action.
- Touch targets are at least 44px; phone primary controls are 52px; phone inputs use 17px.
- Every field has a label; errors are associated; status has text as well as color.
- Keyboard focus is visible; dialogs retain focus, close with Escape and return focus.
- No horizontal overflow, covered action, clipped label or unintended page jump.
- Check reduced motion and text contrast (4.5:1, or 3:1 for large text).
- Attach before/after screenshots and record any intentional visual-reference change.
