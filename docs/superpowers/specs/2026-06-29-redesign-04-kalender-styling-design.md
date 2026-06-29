# Design: Redesign Sub-Projekt 4 — Kalender-Styling

**Datum:** 2026-06-29
**Serie:** UI-Redesign (7 Sub-Projekte)
**Ziel:** Kalender-Grid optisch nach Design-Guide aufwerten — ausschließlich CSS-Änderungen in `calendar-grid.css`.

---

## Kontext

Sub-Projekt 1 hat `calendar-grid.css` bereits mit Grundfarben und Font-Klassen versehen. Sub-Projekt 4 ergänzt die fehlenden Zellen-Inhalts-Styles, Cursor-Verhalten, Hover-Effekte und Label-Typografie.

**Keine Blade-Änderungen** — alle relevanten CSS-Klassen sind bereits in `components/calendar/grid.blade.php` vorhanden.

**Design-Referenz:** `design_handoff/blade-templates/calendar/booking-cell.blade.php`, `time-column.blade.php`, `court-header.blade.php`

---

## Was sich ändert

Nur: `resources/css/calendar-grid.css` — neue Regeln werden ergänzt, bestehende bleiben unverändert.

---

## Neue CSS-Regeln

### 1. `.calendar-cell` — Basis aller Buchungszellen

```css
.calendar-cell {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 4px 6px;
    overflow: hidden;
    text-decoration: none;
    height: 100%;
    box-sizing: border-box;
}
```

### 2. Zellen-Cursor und Hover

```css
.cc-free {
    cursor: pointer;
}

.cc-free:hover {
    background: #f0f7ff;
}

.cc-over {
    cursor: default;
}

.cc-single-future {
    background: #eff6ff;
    cursor: default;
}
```

### 3. `.cc-label-primary` — Buchungsname

```css
.cc-label-primary {
    font-family: 'Red Hat Text', sans-serif;
    font-size: 12px;
    font-weight: 600;
    color: #1a3a6b;
    line-height: 1.3;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    max-width: 100%;
}
```

### 4. `.cc-label-secondary` — Mitspieler

```css
.cc-label-secondary {
    font-family: 'Red Hat Text', sans-serif;
    font-size: 11px;
    color: #5a7ab3;
    line-height: 1.3;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}
```

### 5. `.event-label` — Veranstaltungsname

```css
.event-label {
    font-family: 'Red Hat Text', sans-serif;
    font-size: 12px;
    font-weight: 600;
    color: #7f2010;
    line-height: 1.35;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    text-decoration: none;
}

.event-label:hover {
    text-decoration: underline;
}
```

---

## Was sich NICHT ändert

- Alle bestehenden Regeln in `calendar-grid.css` (Farben, Fonts aus Sub-Projekt 1)
- `grid.blade.php` — keine Blade-Änderungen
- `index.blade.php` — keine Änderungen
- `public/js/booking.js` — kein Eingriff
- `public/css/booking.css` — kein Eingriff

---

## Erfolgskriterien

1. Freie Zellen zeigen `cursor: pointer` und hellen Blau-Hover (`#f0f7ff`)
2. Vergangene Zellen zeigen `cursor: default`
3. Buchungsname in Zelle: 12px, blau-dunkel (`#1a3a6b`), max 2 Zeilen
4. Mitspieler-Label: 11px, mittelblau (`#5a7ab3`), Ellipsis bei Überlänge
5. Veranstaltungsname: 12px, dunkelrot (`#7f2010`), klickbar mit Underline-Hover
6. `npm run build` ohne Fehler
