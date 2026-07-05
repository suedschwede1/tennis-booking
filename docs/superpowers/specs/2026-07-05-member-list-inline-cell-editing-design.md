# Design: Direkt editierbare Zellen in der Mitgliederliste

**Datum:** 2026-07-05
**Status:** Freigegeben

## Ziel

Die bisherige Inline-Bearbeitung in der Mitgliederliste (Commit `2796485`: Klick auf
"Bearbeiten" klappt die Zeile zu einem kompletten `_form`-Partial auf) entspricht nicht
der Erwartung des Nutzers an "Bearbeitung in der Tabellenansicht". Sie wird ersetzt durch
direkt anklickbare, einzeln editierbare Zellen für die wichtigsten Felder.

## Umfang

**Editierbare Spalten** (Klick macht die Zelle zum Eingabefeld):

- Vorname, Nachname — zwei nebeneinanderliegende Textfelder
- E-Mail — Textfeld (type=email)
- Beitragskategorie — Select mit allen `fee_categories` + leerer Option
- Eingetreten am — Datumsfeld

**Nicht editierbar:**

- Mitgliedsnummer (Schlüssel, Duplikat-Risiko)
- Alter (berechnetes Feld, kein Spaltenwert)

**Weiterhin nur über das volle Formular editierbar** (Adresse, Telefon, Geschlecht,
Nuliga-Nr.): Der "Bearbeiten"-Link in der Aktionen-Spalte bleibt erhalten und führt
zur bestehenden Seite `members.edit` (unverändert, mit den drei Formular-Gruppen).

**Unverändert:** Namenssuche, Kategorie-Filter, Status-Filter, Alter-Spalte, Austragen-Aktion.

**Rückbau:** Die Inline-Formular-Lösung aus Task 8 (`?edit={id}` klappt die Zeile zum
`_form`-Partial auf) wird entfernt — der `editing`-Query-Parameter, die zugehörige
Controller-Logik in `index()`/`update()` (Filter-Redirect-Mechanismus) und der
bedingte `@if`-Zweig in der View verschwinden wieder.

## Verhalten

1. Klick auf eine editierbare Zelle ersetzt deren Anzeige-Inhalt durch das passende
   Eingabeelement (Text/Select/Date), vorbefüllt mit dem aktuellen Wert.
2. Verlassen des Felds (blur) oder Enter-Taste löst einen `fetch()`-PATCH-Request aus.
3. Erfolg: Zelle zeigt kurz einen grünen Rahmen (verschwindet nach ~1,5s per
   `setTimeout`), kehrt in den Anzeige-Modus mit dem neuen (ggf. serverseitig
   formatierten) Wert zurück.
4. Fehler (Validierung oder Serverfehler): roter Rahmen, Fehlertext unter dem Feld,
   Feld bleibt im Edit-Modus zur Korrektur.
5. Klick außerhalb ohne Änderung: kein Request, Zelle kehrt unverändert zur Anzeige zurück.
6. Nur eine Zelle ist gleichzeitig im Edit-Modus; Klick auf eine andere Zelle
   schließt die vorherige (ohne Speichern, falls dort keine Änderung erfolgte —
   bei einer ungespeicherten Änderung wird zuerst automatisch gespeichert wie bei blur).

## Neuer Endpunkt

`PATCH /members/{member}/field`

- Body: `{ "field": "firstname", "value": "Anna" }`
- Route + Controller-Methode: `MemberController::updateField(Request, Member)`
- Serverseitige Whitelist der editierbaren Felder: `firstname`, `lastname`, `email`,
  `fee_category_id`, `joined_at`. Jedes andere `field` → `422` mit Fehlermeldung.
- Validierung pro Feld identisch zu den bestehenden Regeln in `store()`/`update()`
  (z. B. `firstname` required|string|max:128, `email` nullable|email|max:128,
  `fee_category_id` nullable|exists:fee_categories,id, `joined_at` required|date).
- Erfolg: `200` mit `{ "value": "<formatierter Wert>" }` — bei `joined_at` z. B.
  `d.m.Y`-formatiert fürs Redisplay; bei `fee_category_id` der Name der Kategorie
  (oder `null`/„—") fürs Redisplay in der Zelle.
- Fehler: `422` mit `{ "message": "<erste Fehlermeldung>" }`.
- Berechtigung: dieselbe wie die bestehenden Member-Routen (`admin.members`-Privileg).

## Frontend

Ein kleines Vanilla-JS-Snippet direkt in `members/index.blade.php` (kein Build-Schritt,
analog zum bestehenden Muster in `admin/bookings/edit.blade.php`):

- Editierbare Zellen tragen `data-field`, `data-member-id`, `data-value` Attribute.
- Klick-Handler (Event-Delegation auf die Tabelle) rendert das passende Eingabeelement.
- Ein gemeinsamer `save(cell)`-Helper baut den PATCH-Request, zeigt Erfolg/Fehler an.
- Fee-Category-Zelle nutzt ein `<select>` mit den bereits vom Controller gelieferten
  `feeCategories` (als JSON in ein `data-`-Attribut auf der Tabelle serialisiert,
  oder als verstecktes `<template>` mit den `<option>`s — letzteres vermeidet
  JSON-Escaping-Fragen und wird bevorzugt).

## Tests

- Feature-Test für `PATCH /members/{member}/field`: Erfolg pro erlaubtem Feld,
  Validierungsfehler pro Feld, Ablehnung eines nicht erlaubten Feldnamens (z. B.
  `member_number`), Berechtigungsprüfung (nicht-Admin → 403).
- Bestehende Tests aus Task 8 (`the_index_renders_an_inline_edit_form_for_the_requested_member`,
  `updating_from_the_list_redirects_back_with_the_active_filters`) werden entfernt,
  da der zugehörige Mechanismus (`?edit={id}`-Formular-Aufklappen) zurückgebaut wird.
- Kein Browser-/JS-Test (kein bestehendes Setup dafür im Projekt); die Fetch-Logik
  wird manuell im Preview verifiziert.

## Nicht im Scope

- Keine weiteren Spalten werden zur Tabelle hinzugefügt.
- Kein Mehrfach-Editieren/Batch-Speichern mehrerer Zellen auf einmal.
- Keine Änderung an Filter, Suche, Austragen-Aktion oder der separaten Bearbeiten-Seite.
