# Platz-Verwaltung (Admin) — Design

**Datum:** 2026-06-28
**Ziel:** Eine Admin-Oberfläche zum Verwalten der Plätze (`bs_squares`) im neuen Laravel-System. Plätze können angelegt, bearbeitet und gelöscht werden; Name **und** Anzeigename (Alias) sind konfigurierbar; die Anzahl der Plätze ist frei einstellbar. Portiert das „Plätze"-Backend des Alt-Systems (ZF2 `ConfigSquareController`).

**Nicht in Scope (späteres Feature):** Das separate Unterformular „Platzinfos und -regeln" (Info-Texte oben/unten, Regeltext, Regel-PDF-Upload). Die zugehörigen Meta-Keys `info.pre`, `info.post`, `rules.text`, `rules.document.file`, `rules.document.name` werden hier nicht angefasst.

---

## 1. Architektur

CRUD-Bereich analog zur bestehenden „Veranstaltungen"-Verwaltung (`Admin\EventController` + `admin/events/{index,_form,create,edit}.blade.php`).

**Neue Dateien:**
- `app/Http/Controllers/Admin/SquareController.php`
- `resources/views/admin/squares/index.blade.php`
- `resources/views/admin/squares/_form.blade.php`
- `resources/views/admin/squares/create.blade.php`
- `resources/views/admin/squares/edit.blade.php`
- Tests: `tests/Feature/Admin/SquareControllerTest.php`

**Geänderte Dateien:**
- `routes/web.php` — Resource-Route `squares`
- `resources/views/layouts/admin.blade.php` — Nav-Eintrag „Plätze"

### Routing & Rechte
Im bestehenden `admin`-Prefix / `name('admin.')`-Block:

```php
Route::middleware('can:admin.config')->group(function (): void {
    // ... bestehende config-Routen ...
    Route::resource('squares', \App\Http\Controllers\Admin\SquareController::class)
        ->except(['show']);
});
```

Gating über `admin.config` — Plätze zählen zur Systemkonfiguration; es gibt kein eigenes `admin.square`-Privileg in `User::PRIVILEGES`. Der Nav-Link „Plätze" ist mit `@can('admin.config')` sichtbar.

---

## 2. Datenmodell & Feld-Mapping

Speicherung in `bs_squares` (Spalten) bzw. `bs_squares_meta` (Key/Value, `locale = null`). Das System ist einsprachig (de); Meta-Zeilen werden immer mit `locale = null` geschrieben/gelesen.

| Formularfeld | Ziel | Typ / Einheit | Umrechnung Formular → DB |
|---|---|---|---|
| Name | `bs_squares.name` | string(64) | — |
| Anzeigename (Alias) | Meta `alias` | string | — |
| Status | `bs_squares.status` | `enabled` \| `readonly` \| `disabled` | — |
| Nachricht (bei readonly) | Meta `readonly.message` | text | — |
| Priorität | `bs_squares.priority` | float | — |
| Kapazität | `bs_squares.capacity` | int (Spieler) | — |
| Namen anderer Spieler | Meta `capacity-ask-names` | select (9 Optionen, s. u.) | — |
| Mehrfachbuchungen | `bs_squares.capacity_heterogenic` | bool (0/1) | Checkbox → 0/1 |
| Anmerkungen erlauben | `bs_squares.allow_notes` | bool (0/1) | Checkbox → 0/1 |
| Sichtbarkeit von Namen | Meta `private_names` + `public_names` | select (keine/privat/öffentlich) | s. u. |
| Zeit (Beginn) | `bs_squares.time_start` | TIME | `HH:MM` → `HH:MM:00` |
| Zeit (Ende) | `bs_squares.time_end` | TIME | `HH:MM` → `HH:MM:00` |
| Zeitblock | `bs_squares.time_block` | Sekunden | Minuten × 60 |
| Zeitblock (min. buchbar) | `bs_squares.time_block_bookable` | Sekunden | Minuten × 60 |
| …nur für Verwaltung | Meta `pseudo-time-block-bookable` | `'true'`/`'false'` | Checkbox |
| Zeitblock (max. buchbar) | `bs_squares.time_block_bookable_max` | Sekunden | Minuten × 60 |
| Buchungsvorlauf | `bs_squares.min_range_book` | Sekunden | Minuten × 60 |
| Buchung im Voraus | `bs_squares.range_book` | Sekunden | Tage × 86400 |
| Buchungen einschränken | `bs_squares.max_active_bookings` | int (0 = unbegrenzt) | — |
| Stornierung | `bs_squares.range_cancel` | Sekunden | Stunden × 3600 |
| Bezeichnung freier Plätze | Meta `label.free` | string | — |

**Rückrechnung beim Laden (DB → Formular):** `time_block`, `time_block_bookable`, `time_block_bookable_max`, `min_range_book` → ÷ 60 (Minuten); `range_book` → ÷ 86400 (Tage); `range_cancel` → ÷ 3600 (Stunden, max. 1 Nachkommastelle). TIME-Felder auf `HH:MM` kürzen.

### Dropdown „Namen anderer Spieler" (`capacity-ask-names`)
Werte: `` (leer = nicht fragen), `optional-names`, `optional-names-email`, `optional-names-phone`, `optional-names-email-phone`, `required-names`, `required-names-email`, `required-names-phone`, `required-names-email-phone`.

### Dropdown „Sichtbarkeit von Namen"
Ein Select mit drei Optionen, das auf zwei Meta-Keys abbildet:
- **keine** → `private_names='false'`, `public_names='false'`
- **privat** (nur angemeldete Benutzer) → `private_names='true'`, `public_names='false'`
- **öffentlich** (alle) → `private_names='true'`, `public_names='true'`

Beim Laden umgekehrt: `public_names='true'` → öffentlich, sonst `private_names='true'` → privat, sonst keine.

---

## 3. Controller-Verhalten

`SquareController` (final, `declare(strict_types=1)`):

- **index** — alle Plätze nach `priority`, `sid` sortiert; Liste mit Name, Anzeigename, Status, Zeit, Zeitblöcken; Buttons „Bearbeiten"/„Löschen" + „Neuer Platz".
- **create / edit** — gemeinsames `_form.blade.php`; bei `create` Default-Werte (s. u.), bei `edit` Werte aus Spalten + Meta (mit Rückrechnung).
- **store / update** — Validierung (s. u.), Einheiten-Umrechnung, Spalten schreiben, Meta-Keys upserten (leere Werte löschen die Meta-Zeile). In einer `DB::transaction`.
- **destroy** — **Soft-Delete-Guard:** Existiert mind. eine aktive/inaktive Buchung (`bs_bookings.sid = sid`) → kein Löschen, stattdessen `status = 'disabled'` + Hinweis „Platz hat Buchungen und wurde deaktiviert statt gelöscht." Andernfalls Platz + zugehörige `bs_squares_meta`-Zeilen hart löschen.

### Meta-Helfer
`Square` hat bereits `getMeta()`. Ergänzend wird ein `setMeta(string $key, ?string $value)` analog zu `User::setMeta()` benötigt (Upsert auf `key` mit `locale = null`; `null` löscht die Zeile). Dies wird dem `Square`-Model hinzugefügt.

### Default-Werte für neuen Platz
status=`enabled`, priority=`1`, capacity=`1`, capacity_heterogenic=`false`, allow_notes=`false`, time_start=`08:00`, time_end=`23:00`, time_block=`60` Min, time_block_bookable=`30` Min, pseudo-time-block-bookable=`false`, time_block_bookable_max=`180` Min, min_range_book=`0` Min, range_book=`56` Tage, max_active_bookings=`0`, range_cancel=`24` Std.

---

## 4. Validierung

- `name` — required, string, max 64
- `alias` — nullable, string, max 64
- `status` — required, in `enabled,readonly,disabled`
- `readonly_message` — nullable, string
- `priority` — required, numeric
- `capacity` — required, integer, min 0
- `capacity_ask_names` — nullable, in (Liste oben)
- `capacity_heterogenic`, `allow_notes`, `pseudo_time_block_bookable` — boolean (Checkbox)
- `name_visibility` — required, in `none,private,public`
- `time_start`, `time_end` — required, regex `^\d{2}:\d{2}$`
- `time_block`, `time_block_bookable`, `time_block_bookable_max`, `min_range_book`, `range_book`, `max_active_bookings` — required, integer, min 0
- `range_cancel` — required, numeric, min 0 (max. 1 Nachkommastelle)
- `label_free` — nullable, string, max 64

---

## 5. Tests (`tests/Feature/Admin/SquareControllerTest.php`)

Nach dem Muster bestehender Admin-Feature-Tests, mit dem aktuellen Test-Setup (Factories / Test-DB):

- index zeigt vorhandene Plätze.
- store legt Platz an: Spalten korrekt, Einheiten-Umrechnung korrekt (z. B. 60 Min → 3600 Sek, 56 Tage → 4838400 Sek, 24 Std → 86400 Sek), Meta `alias` gesetzt.
- „Sichtbarkeit von Namen"-Mapping schreibt `private_names`/`public_names` korrekt (alle 3 Fälle).
- edit lädt Werte inkl. Rückrechnung (Sek → Min/Tage/Std).
- update ändert Spalten + Meta; leerer Alias löscht die Meta-Zeile.
- destroy ohne Buchungen → Platz + Meta gelöscht.
- destroy mit Buchungen → Platz bleibt, `status = 'disabled'`.
- Rechte: Zugriff ohne `admin.config` → 403.

---

## 6. Offene Punkte / Annahmen

- **Gating** über `admin.config` (kein eigenes Platz-Privileg). Falls ein eigenes `admin.square`-Recht gewünscht ist, müsste `User::PRIVILEGES` erweitert werden — bewusst nicht Teil dieses Designs.
- **Anzeigename vs. Kalender:** Der Kalender nutzt `display_name = alias ?? Legacy-Name ?? name`. Setzen des Alias steuert damit direkt die Kalender-Bezeichnung; die Legacy-Fallbacks (Garagen-/Star-/Leitenplatz für name 1/2/3) bleiben als Rückfall erhalten.
- Meta immer `locale = null` (einsprachig).
