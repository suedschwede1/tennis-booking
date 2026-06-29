# Design: Redesign Sub-Projekt 1 — Infrastruktur

**Datum:** 2026-06-29
**Serie:** UI-Redesign (7 Sub-Projekte)
**Ziel:** Tailwind v4 CSS-Basis + Alpine.js einrichten, ohne bestehende Kalender-Grid-Logik zu verändern.

---

## Kontext

Das Projekt verwendet bereits Tailwind CSS v4 (via `@tailwindcss/vite` in `vite.config.js`), hat aber noch keine `resources/css/app.css`. Alpine.js und Flux UI sind nicht installiert. **Flux UI wird nicht eingesetzt** — pures Tailwind v4 + Alpine.js.

Das bestehende Custom-CSS (Kalender-Grid) wird in eine separate Datei ausgelagert und per `@import` eingebunden, nicht ersetzt.

---

## Was geändert wird

| Datei | Aktion |
|---|---|
| `resources/css/app.css` | Neu erstellen |
| `resources/css/calendar-grid.css` | Neu erstellen (bestehende Kalender-CSS hierher migrieren) |
| `resources/js/app.js` | Alpine.js hinzufügen |
| `layouts/app.blade.php` | Google Fonts `<link>` hinzufügen |
| `package.json` | `alpinejs` hinzufügen |

### Was NICHT geändert wird

- `vite.config.js` — bereits korrekt konfiguriert
- `public/js/booking.js` — wird in Sub-Projekt 5 migriert
- Blade-Views — werden in späteren Sub-Projekten umgestellt
- Kalender-Grid-Logik (`--calendar-slot-col`, `--calendar-time-col`, Responsive-JS)

---

## `resources/css/app.css`

```css
@import 'tailwindcss';

@theme {
    /* Club-Orange Palette */
    --color-accent-50:  oklch(97%   0.012 35);
    --color-accent-100: oklch(93%   0.030 35);
    --color-accent-200: oklch(86%   0.060 35);
    --color-accent-300: oklch(76%   0.100 35);
    --color-accent-400: oklch(64%   0.150 35);
    --color-accent-500: oklch(57%   0.178 35);
    --color-accent-600: oklch(51%   0.190 35); /* = #bf4316 */
    --color-accent-700: oklch(43%   0.168 35); /* hover = #9e3412 */
    --color-accent-800: oklch(35%   0.138 35);
    --color-accent-900: oklch(28%   0.100 35);
    --color-accent-950: oklch(20%   0.070 35);

    /* Typografie */
    --font-display: 'Red Hat Display', sans-serif;
    --font-body:    'Red Hat Text', sans-serif;
}

@import './calendar-grid.css';
```

---

## `resources/css/calendar-grid.css`

Enthält alle bestehenden Kalender-Custom-Properties und CSS-Klassen, die aktuell in einer bestehenden CSS-Datei oder inline definiert sind. Zusätzlich werden folgende Werte aus dem Design-Guide angepasst:

| Klasse | Eigenschaft | Neuer Wert |
|---|---|---|
| `.cc-free` | `background` | `#ffffff` |
| `.cc-own` | `background` | `#eff6ff` |
| `.event-cell` | `background` | `#fde8e1` |
| `.cc-over` | `background` | `#f4f4f4` |
| `.time-main` | `font-family` | Red Hat Display, 17px, bold |
| `.time-sub` | `font-family` | Red Hat Text, 11px, `#b8b8b8` |
| `.day-header-name` | `font-family` | Red Hat Display, bold |
| `.day-header-date` | `font-family` | Red Hat Text, `#6a6e73` |

Grid-Variablen (`--calendar-slot-col`, `--calendar-time-col`) und alle Responsive-Klassen (`cal-extra-day`, `is-visible`) bleiben **unverändert**.

---

## `resources/js/app.js`

```js
import Alpine from 'alpinejs'
window.Alpine = Alpine
Alpine.start()
```

---

## `layouts/app.blade.php`

Google Fonts `<link>` im `<head>` hinzufügen:

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@600;700&family=Red+Hat+Text:wght@400;500;600&display=swap" rel="stylesheet">
```

---

## Erfolgskriterien

Nach Implementierung:
1. `npm run dev` läuft ohne Fehler
2. Im Browser: Red Hat Display / Red Hat Text werden geladen (DevTools → Network → Fonts)
3. Kalender-Grid wird korrekt dargestellt — keine visuellen Regressionen
4. Alpine.js verfügbar: `window.Alpine` in der Browser-Konsole gibt das Alpine-Objekt zurück
5. Club-Orange als CSS-Variable: `getComputedStyle(document.documentElement).getPropertyValue('--color-accent-600')` gibt einen oklch-Wert zurück

---

## Reihenfolge der Sub-Projekte

1. **→ Infrastruktur** ← (dieser Spec)
2. Layouts
3. Auth
4. Kalender-Styling
5. Buchungs-Modals
6. Account
7. Admin-Views
