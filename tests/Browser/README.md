# Browser and visual design checks

Run PHP/Composer through WSL on Windows. Install PHP gd for pixel comparison, and a
Chrome/Chromium browser with a matching ChromeDriver. Use `php artisan dusk:chrome-driver
--detect` when using the bundled driver. For an externally managed driver, set
`DUSK_DRIVER_URL`, `DUSK_CHROME_BINARY` and `DUSK_SKIP_CHROMEDRIVER=1` so Dusk does
not start another driver. Without the skip flag it starts the bundled driver normally.

Copy `.env.dusk.example` to `.env.dusk.local`. Use an isolated SQLite database, `de`
locale, a cookie session, array cache and array/log mailer; never use a live database.
Both the test process and server must point at the same environment and database.

```bash
wsl php artisan serve --env=dusk.local --host=127.0.0.1 --port=8088 --no-reload
wsl composer dusk:visual
```

`dusk:visual` selects both `VisualRegressionTest` and `DesignVisualRegressionTest`:
12 viewport references covering calendar, booking modal, login, account, admin,
shared controls and the phone dialog. The new controls additionally check phone
target sizes, horizontal overflow and Escape/focus restoration. The admin current-date
label is masked. July 2099 is used for bookable calendar fixtures; no clock hook is
enabled in the production application.

Create or intentionally refresh references locally (from a WSL shell):

```bash
UPDATE_DUSK_SNAPSHOTS=1 php vendor/bin/phpunit -c phpunit.dusk.xml --filter VisualRegressionTest
```

Inspect all generated PNGs in `tests/Browser/baselines` before committing. Missing
baselines or PHP gd are failures. CI refuses reference replacement and uploads actual
screenshots, browser logs and the browser version as `design-visual-results`.

Initial references were captured on Linux/WSL with Chromium/ChromeDriver
156.0.8078.79, German app locale, default en-US browser locale and device scale 1.
Browser/OS/font rasterization changes can require a deliberately reviewed refresh;
the workflow records the runner browser version to distinguish these from UI changes.
The new kit uses local Barlow fonts. Legacy pages still use their existing font loading.

The legacy-page references guard against accidental changes; they are **not target
mockup approval**. Track target migration and manual accessibility checks in
`docs/DESIGN-ASSURANCE.md`. Fresh actuals are ignored under `screenshots/`; reviewed
baselines are tracked. Run all functional browser checks with `wsl composer dusk`.
