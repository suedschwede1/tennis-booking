# Admin-Layout im Alt-System-Stil — Design

**Datum:** 2026-06-28
**Ziel:** Das Layout der Admin-Seiten an das Alt-System (ZF2-Backend) angleichen. Die **Eingabeformulare** sollen weitgehend identisch wirken (Label/Eingabe-Zeilen mit kursiven Hinweistexten); die **Navigation darf verbessert** werden (gestaltete Top-Navi statt der Zurück-Links des Originals). Helles Theme passend zu den vorhandenen Screenshots, integriert in die bestehende `public/css/booking.css`. Keine Controller-/Logik-Änderungen.

---

## 1. Optik-Vorgaben (aus dem Alt-System)

- **Panel:** zentrierter weißer Inhaltsblock auf hellem Seitenhintergrund, abgerundete Ecken, dezenter Schatten, max-width ~1024px.
- **Überschrift:** `h1` oben, darunter optionale Beschreibungszeile, dann eine dünne Trennlinie.
- **Tabellen:** umrandet (`1px #ccc`), graue Kopfzeile, Zellen-Padding ~8px (wie Plätze-Liste).
- **Buttons:** die vorhandenen grauen Gradient-`.default-button` (passen bereits).
- **Formulare:** Label **rechtsbündig in linker Spalte**, Eingabe in rechter Spalte; darunter/daneben kursive Hinweistexte (`#666`, ~11px); Submit zentriert/links unter dem Raster.
- **Farben:** Panel-Text `#333`, Überschriften `#547B97`, Rahmen `#ccc`/`#999`, Hinweis `#666`, Erfolg `#393`, Fehler `#933`.
- **Schrift:** bestehende System-Schrift beibehalten (kein erzwungenes Verdana) — Größen an die kompakte Alt-Optik angenähert.

## 2. Layout-Chrome — `resources/views/layouts/admin.blade.php`

- Heller Seitenhintergrund.
- Kopfbereich: Titel „Administration" + **Top-Navi** (verbessert): die bestehenden Links (Übersicht, Benutzer, Veranstaltungen, Buchungen, Plätze, Konfiguration, Zum Kalender) als `.default-button`, mit **Aktiv-Zustand** (`.is-active`) für die aktuelle Sektion.
- Inhalt in einem zentrierten **`.admin-panel`** gerendert (`@yield('admin-content')`).
- Erfolg-/Fehlermeldungen oberhalb des Panels im Alt-Stil (`.admin-flash--success` / `--error`).

Aktiv-Zustand: per `request()->routeIs('admin.squares.*')` o. ä. je Nav-Link.

## 3. CSS-Bausteine (Abschnitt in `public/css/booking.css`)

Neue Klassen (Präfix `admin-`, um Kollisionen mit dem Kalender-CSS zu vermeiden):

- `.admin-page` — heller Seitenhintergrund + Grundlayout.
- `.admin-nav` + `.admin-nav .default-button.is-active` — Navi + Aktiv-Zustand.
- `.admin-panel` — zentriertes weißes Panel (max-width 1024px, padding 32px, radius, shadow).
- `.admin-panel h1`, `.admin-intro` (Beschreibungszeile, `#666`), `.admin-separator` (1px `#ccc`).
- `.admin-table` — umrandete Tabelle, graue Kopfzeile (`th` Hintergrund `#eee`, Rahmen `#ccc`, Zell-Padding 8px).
- `.admin-form` — Raster für Formularzeilen:
  - `.admin-form__row` (Label-Spalte + Eingabe-Spalte; auf schmalen Viewports gestapelt),
  - `.admin-form__label` (rechtsbündig, `#547B97`),
  - `.admin-form__note` (kursiv, `#666`, ~11px),
  - `.admin-form__actions` (Submit-Bereich).
- Eingabe-Styles (`input/select/textarea`) an die Alt-Optik angenähert, sofern nicht schon global vorhanden.

Verantwortung: rein additiv; bestehende Klassen (`.default-button`, ggf. `.booking-grid`) bleiben unangetastet.

## 4. Admin-Views angleichen

Einheitliches Muster pro Seite: `h1` → optionale `.admin-intro` → `.admin-separator` → Inhalt.

**Listen → `.admin-table`:**
- `admin/squares/index.blade.php` (ersetzt `.booking-grid`)
- `admin/events/index.blade.php`
- `admin/users/index.blade.php`
- `admin/bookings/index.blade.php`

**Formulare → `.admin-form` (Label/Eingabe-Zeilen + Hinweise):**
- `admin/config/edit.blade.php` (Konfiguration)
- `admin/squares/_form.blade.php` (langes Platz-Formular; lange Erläuterungen werden zu `.admin-form__note`)
- `admin/events/_form.blade.php`
- `admin/users/_form.blade.php`
- Hinweis: `admin/bookings/edit.blade.php` nutzt bereits ein eigenes, kartenbasiertes Layout (`admin-booking-*`). Es wird **nicht** auf `.admin-form` umgestellt, sondern nur an Panel/Farben angepasst, soweit nötig (kein Bruch des bestehenden Buchungs-Formulars).

**Detail/Übersicht:**
- `admin/dashboard.blade.php`, `admin/bookings/show.blade.php` — an Panel/Tabellen-Optik angleichen.

## 5. Tests & Verifikation

- Keine sichtbaren **Textänderungen** an Labels/Überschriften, die bestehende `assertSee`-Tests brechen würden (Feldnamen/`name`-Attribute bleiben unverändert).
- Volle PHPUnit-Suite muss grün bleiben (167 Tests).
- Visuelle Verifikation im laufenden Server (`php artisan serve`) für je eine Liste (Plätze) und ein Formular (Konfiguration, Platz-Formular).

## 6. Nicht in Scope

- Keine Änderung an Controllern, Routen, Validierung, Models.
- Keine Übernahme des dunklen ZF2-Hintergrunds (helles Theme bleibt).
- Kein Icon-Font/Sprite-Port; „Neuer …"-Buttons bleiben textbasiert (optional dezentes „+" als Text).
- Das bestehende `admin-booking-*`-Buchungsformular wird nicht neu strukturiert.
