# UI-Redesign Kalender-Styling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `calendar-grid.css` um Zellen-Inhalts-Styles, Cursor-Verhalten, Hover-Effekte und Label-Typografie nach Design-Guide ergänzen.

**Architecture:** Rein CSS — keine Blade-Änderungen. Alle Klassen (`.calendar-cell`, `.cc-label-primary`, `.cc-label-secondary`, `.event-label`) sind bereits in `grid.blade.php` vorhanden. Die neuen Regeln werden an den bestehenden Inhalt von `calendar-grid.css` angehängt.

**Tech Stack:** CSS, Laravel Vite (Build-Step nach Änderung)

---

## File Structure

| Aktion | Datei |
|---|---|
| Modifizieren | `resources/css/calendar-grid.css` |
| Build-Update | `public/build/` |

---

### Task 1: `calendar-grid.css` um Zellen-Styles ergänzen

**Files:**
- Modify: `resources/css/calendar-grid.css`

- [ ] **Schritt 1: Datei lesen**

Lies `C:\development\bookingnew\resources\css\calendar-grid.css` um den aktuellen Inhalt zu sehen (59 Zeilen). Die neuen Regeln werden am Ende der Datei angehängt.

- [ ] **Schritt 2: Neue Regeln anhängen**

Füge folgenden Block **am Ende** der Datei `C:\development\bookingnew\resources\css\calendar-grid.css` hinzu:

```css

/* Buchungszellen — Basis-Layout */
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

/* Freie Zelle — klickbar mit Hover */
.cc-free {
    cursor: pointer;
}

.cc-free:hover {
    background: #f0f7ff;
}

/* Vergangene Zelle — nicht klickbar */
.cc-over {
    cursor: default;
}

/* Fremde Buchung — gleiche Farbe wie eigene, nicht klickbar */
.cc-single-future {
    background: #eff6ff;
    cursor: default;
}

/* Buchungsname in Zelle */
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

/* Mitspieler-Label */
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

/* Veranstaltungsname */
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

- [ ] **Schritt 3: Commit**

```bash
git add resources/css/calendar-grid.css
git commit -m "feat(calendar): Zellen-Styles, Hover und Label-Typografie nach Design-Guide"
```

---

### Task 2: Build aktualisieren und committen

**Files:** `public/build/`

- [ ] **Schritt 1: Build ausführen**

```bash
cd C:\development\bookingnew
npm run build
```

Erwartung: Kein Fehler. Die neue CSS-Datei enthält alle neuen Klassen.

- [ ] **Schritt 2: Build committen**

```bash
git add public/build/
git commit -m "chore: Vite-Build nach Kalender-Styling aktualisiert"
```

---

## Manuelle Verifikation

Im Browser den Kalender (`/`) öffnen und prüfen:

1. Freie Zellen: `cursor: pointer`, hellblauer Hover (`#f0f7ff`) beim Drüberfahren
2. Vergangene Zellen: `cursor: default`, kein Hover-Effekt
3. Eigene Buchungen: blaue Zelle (`#eff6ff`), Buchungsname dunkelblau (`#1a3a6b`), 12px bold
4. Fremde Buchungen: gleiches Blau (`#eff6ff`), Buchungsname sichtbar wenn eingeloggt
5. Mitspieler-Label: mittelblau (`#5a7ab3`), 11px, Ellipsis bei Überlänge
6. Veranstaltungen: orangerot (`#fde8e1`), Name dunkelrot (`#7f2010`), Underline beim Hover auf editierbaren Veranstaltungen
