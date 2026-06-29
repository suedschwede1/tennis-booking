# UI-Redesign Infrastruktur Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tailwind v4 CSS-Pipeline + Alpine.js + Google Fonts einrichten, ohne bestehende booking.css/booking.js zu entfernen.

**Architecture:** Vite ist bereits konfiguriert (`resources/css/app.css` + `resources/js/app.js`), aber beide Dateien existieren noch nicht. Die bestehende `public/css/booking.css` (2804 Zeilen) bleibt parallel aktiv — sie wird in späteren Sub-Projekten schrittweise ersetzt. `calendar-grid.css` enthält sofort die Design-Guide-Farbkorrekturen für den Kalender.

**Tech Stack:** Tailwind CSS v4, Alpine.js, Google Fonts (Red Hat Display + Red Hat Text), Laravel Vite Plugin

---

## File Structure

| Aktion | Datei | Zweck |
|---|---|---|
| Erstellen | `resources/css/app.css` | Tailwind @import + @theme (Farben, Fonts) |
| Erstellen | `resources/css/calendar-grid.css` | Kalender-Farbkorrekturen nach Design-Guide |
| Erstellen | `resources/js/app.js` | Alpine.js initialisieren |
| Modifizieren | `vite.config.js` | Bunny-Fonts entfernen |
| Modifizieren | `resources/views/layouts/app.blade.php` | Google Fonts + @vite() hinzufügen |

**Nicht geändert:** `public/css/booking.css`, `public/js/booking.js`, alle Blade-Views außer `layouts/app.blade.php`

---

### Task 1: Alpine.js installieren

**Files:**
- Modify: `package.json`

- [ ] **Schritt 1: Alpine.js installieren**

```bash
cd C:\development\bookingnew
npm install alpinejs
```

Erwartung: `package.json` enthält danach `"alpinejs"` unter `dependencies`.

- [ ] **Schritt 2: Installation prüfen**

```bash
cat node_modules/alpinejs/package.json | grep '"version"'
```

Erwartung: `"version": "3.x.x"`

- [ ] **Schritt 3: Commit**

```bash
git add package.json package-lock.json
git commit -m "chore: alpinejs installiert"
```

---

### Task 2: `vite.config.js` bereinigen

**Files:**
- Modify: `vite.config.js`

Aktuell enthält `vite.config.js` einen `bunny('Instrument Sans', ...)` Font-Loader, der durch Google Fonts ersetzt wird. Dieser muss entfernt werden.

- [ ] **Schritt 1: Bunny-Fonts aus vite.config.js entfernen**

Ersetze den gesamten Inhalt von `C:\development\bookingnew\vite.config.js` durch:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
```

- [ ] **Schritt 2: Commit**

```bash
git add vite.config.js
git commit -m "chore: Bunny-Fonts aus vite.config entfernt"
```

---

### Task 3: `resources/js/app.js` erstellen

**Files:**
- Create: `resources/js/app.js`

- [ ] **Schritt 1: Datei erstellen**

Erstelle `C:\development\bookingnew\resources\js\app.js` mit folgendem Inhalt:

```js
import Alpine from 'alpinejs'
window.Alpine = Alpine
Alpine.start()
```

- [ ] **Schritt 2: Commit**

```bash
git add resources/js/app.js
git commit -m "feat: Alpine.js initialisiert"
```

---

### Task 4: `resources/css/calendar-grid.css` erstellen

**Files:**
- Create: `resources/css/calendar-grid.css`

Diese Datei enthält die Kalender-Farbkorrekturen nach Design-Guide. Die Grid-Variablen und Responsive-Klassen bleiben wie in `public/css/booking.css` — nur Farben und Schriften werden überschrieben.

- [ ] **Schritt 1: Datei erstellen**

Erstelle `C:\development\bookingnew\resources\css\calendar-grid.css` mit folgendem Inhalt:

```css
/* Kalender-Zellfarben nach Design-Guide */
.cc-free {
    background: #ffffff;
}

.cc-own {
    background: #eff6ff;
}

.event-cell {
    background: #fde8e1;
}

.cc-over {
    background: #f4f4f4;
}

/* Zeitspalte — Typografie */
.time-main {
    font-family: 'Red Hat Display', sans-serif;
    font-size: 17px;
    font-weight: 700;
    color: #151515;
}

.time-sub {
    font-family: 'Red Hat Text', sans-serif;
    font-size: 11px;
    color: #b8b8b8;
}

/* Tagesköpfe — Typografie */
.day-header-name {
    font-family: 'Red Hat Display', sans-serif;
    font-weight: 700;
    font-size: 13px;
    color: #151515;
}

.day-header-date {
    font-family: 'Red Hat Text', sans-serif;
    font-size: 11px;
    color: #6a6e73;
}

/* Platznummern */
.square-head-title {
    font-family: 'Red Hat Display', sans-serif;
    font-size: 12px;
    font-weight: 700;
    color: #bf4316;
}

.square-head-alias {
    font-family: 'Red Hat Text', sans-serif;
    font-size: 10px;
    color: #bf4316;
}
```

- [ ] **Schritt 2: Commit**

```bash
git add resources/css/calendar-grid.css
git commit -m "feat: calendar-grid.css mit Design-Guide-Farben"
```

---

### Task 5: `resources/css/app.css` erstellen

**Files:**
- Create: `resources/css/app.css`

- [ ] **Schritt 1: Datei erstellen**

Erstelle `C:\development\bookingnew\resources\css\app.css` mit folgendem Inhalt:

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
    --color-accent-600: oklch(51%   0.190 35);
    --color-accent-700: oklch(43%   0.168 35);
    --color-accent-800: oklch(35%   0.138 35);
    --color-accent-900: oklch(28%   0.100 35);
    --color-accent-950: oklch(20%   0.070 35);

    /* Typografie */
    --font-display: 'Red Hat Display', sans-serif;
    --font-body:    'Red Hat Text', sans-serif;
}

@import './calendar-grid.css';
```

- [ ] **Schritt 2: Commit**

```bash
git add resources/css/app.css
git commit -m "feat: app.css mit Tailwind @theme und Club-Orange-Palette"
```

---

### Task 6: `layouts/app.blade.php` aktualisieren

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

Aktuell lädt das Layout `public/css/booking.css` und `public/js/booking.js` direkt per `asset()`. Wir ergänzen Google Fonts und den Vite-Build. Die bestehenden `asset()`-Aufrufe bleiben — sie werden in späteren Sub-Projekten entfernt.

- [ ] **Schritt 1: Google Fonts + @vite() einfügen**

Lies `resources/views/layouts/app.blade.php`. Füge folgende Zeilen **vor** `<link rel="stylesheet" href="{{ asset('css/booking.css') }}...">` ein (Zeile 12):

```blade
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@600;700&family=Red+Hat+Text:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
```

Das Ergebnis im `<head>` sieht dann so aus:

```blade
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@600;700&family=Red+Hat+Text:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/booking.css') }}?v={{ filemtime(public_path('css/booking.css')) }}">
    @stack('head')
```

- [ ] **Schritt 2: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat: Google Fonts + Vite-Pipeline in app.blade.php eingebunden"
```

---

### Task 7: `.gitignore` anpassen und Build committen

**Wichtig:** Das Projekt läuft auf **one.com Shared Hosting** (kein Node.js auf dem Server). Der Vite-Build muss lokal ausgeführt und `public/build/` committed werden — nur so landet er am Server.

**Files:**
- Modify: `.gitignore`

- [ ] **Schritt 1: `public/build` aus `.gitignore` entfernen**

Lies `.gitignore`. Entferne die Zeile `/public/build` (aktuell Zeile 17).

- [ ] **Schritt 2: `npm run build` ausführen**

```bash
cd C:\development\bookingnew
npm run build
```

Erwartung: Kein Fehler. Output enthält gehashte `.css` und `.js` Dateien unter `public/build/assets/` sowie `public/build/manifest.json`.

- [ ] **Schritt 3: Build-Output prüfen**

```bash
ls public/build/assets/
```

Erwartung: Mindestens eine Datei wie `app-[hash].css` und `app-[hash].js` sichtbar.

- [ ] **Schritt 4: Alles committen**

```bash
git add .gitignore public/build/
git commit -m "chore: public/build aus .gitignore entfernt, initialer Vite-Build committed"
```

---

## Manuelle Verifikation (nach npm run dev)

Nach `npm run dev` im Browser prüfen:

1. **Fonts geladen:** DevTools → Network → Filter "Font" → `RedHatDisplay` und `RedHatText` erscheinen
2. **Alpine.js aktiv:** Browser-Konsole → `window.Alpine` → gibt Alpine-Objekt zurück (kein `undefined`)
3. **CSS-Variable gesetzt:** Browser-Konsole → `getComputedStyle(document.documentElement).getPropertyValue('--color-accent-600')` → gibt oklch-Wert zurück
4. **Kalender unverändert:** Kalender-Grid wird korrekt dargestellt, keine visuellen Regressionen

## Deployment-Workflow (one.com)

Nach jedem Vite-relevanten Commit:
```bash
npm run build
git add public/build/
git commit -m "chore: Vite-Build aktualisiert"
# dann per FTP public/build/ auf den Server übertragen
```
