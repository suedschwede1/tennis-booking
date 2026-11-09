# Reviewed viewport references

Captured on 2026-11-05: Linux/WSL, Chromium and ChromeDriver 156.0.8078.79,
German app locale, default device scale 1. Tests explicitly use en-GB browser
language for the day/month/year native date control shown in the references.

| Files | Fixed CSS viewport | Status |
|---|---|---|
| `design-controls-desktop.png` | 1440 × 857 | Target component foundation |
| `design-controls-mobile.png` | 390 × 857 | Target component foundation; visible controls at least 52px |
| `design-dialog-mobile.png` | 390 × 701 | Target phone dialog; native focus containment and Escape |
| `calendar-desktop.png`, `calendar-mobile.png` | 1440 × 757 / 390 × 701 | Legacy regression reference |
| `booking-modal.png` | 1024 × 625 | Legacy regression reference |
| `login-desktop.png`, `login-mobile.png` | 1440 × 757 / 390 × 701 | Legacy regression reference |
| `account-desktop.png`, `account-mobile.png` | 1440 × 757 / 390 × 701 | Legacy regression reference |
| `admin-desktop.png`, `admin-mobile.png` | 1440 × 757 / 390 × 701 | Legacy regression reference; phone sidebar/content clipping remains migration debt |

The original captures used outer window dimensions that included 143px of browser
decoration. Tests now set these explicit content viewport sizes through CDP so
headless/headful window decoration cannot change screenshot geometry. No reference
image was resized or replaced. The comparison checks exact PNG dimensions as well.
These images are inspected regression
references, not blanket approval of every legacy screen against the new design.

Fixtures use synthetic users, fixed branding without an external logo, and bookable
July 2099 reservations. The admin current-date label is fixed for capture. Local
translations/calendar overrides must be absent for capture/compare. Keep browser,
OS and fonts consistent; inspect any refresh using `../README.md` and
`../../../docs/DESIGN-ASSURANCE.md`.
