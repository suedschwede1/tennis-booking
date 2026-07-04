# Design: Mitglieder-Stammdaten erweitern (Adresse, Geschlecht, Nuliga-Nr., Beitragskategorie)

**Datum:** 2026-07-04
**Status:** Freigegeben

## Ziel

Das Mitglieder-Eingabeformular und das Datenmodell im Members-Modul werden erweitert:

1. Adresse wird in Straße, PLZ und Ort getrennt (bisher ein Feld `address`).
2. Geschlecht wird als neues Feld eingeführt (m/w/d, optional).
3. Die ID-Nr aus der Nuliga-Mitgliederverwaltung wird als `nuliga_id` gespeichert.
4. Die Beitragskategorie wird als Standard-Kategorie direkt am Mitglied vermerkt
   (bisher nur an der jährlichen Vorschreibung `member_dues.fee_category_id`).
5. Das Formular wird in logische Gruppen gegliedert.

## 1. Datenmodell

Neue Alter-Migration auf `members` (die bestehenden Create-Migrationen bleiben unverändert,
da lokal bereits importierte Daten existieren können):

| Feld | Typ | Constraints |
|---|---|---|
| `street` | string(255) | nullable |
| `zip` | string(10) | nullable |
| `city` | string(128) | nullable |
| `gender` | string(1): `m`/`w`/`d` | nullable |
| `nuliga_id` | unsignedInteger | nullable, unique |
| `fee_category_id` | FK → `fee_categories` | nullable, `nullOnDelete` |

- `address` wird entfernt. Bestehende Werte werden in der Migration gesplittet:
  Format ist „Straße, PLZ Ort" (stammt aus `buildAddress()` des Imports).
  Parsing: Split am letzten `", "` → Straße; im Rest führende Ziffernfolge → PLZ, Rest → Ort.
  Nicht parsebare Werte landen komplett in `street`.
- `Member`-Model: `$fillable`/Relation `feeCategory()` (BelongsTo) ergänzen, `address` entfernen.

## 2. Eingabeformular (`members/_form.blade.php`)

Drei Gruppen statt einem flachen Grid:

- **Person:** Vorname, Nachname, Geburtsdatum, Geschlecht (Select: leer/m/w/d)
- **Kontakt:** E-Mail, Telefon, Straße, PLZ + Ort (PLZ schmal, nebeneinander)
- **Verein:** Mitgliedsnummer, Nuliga-Nr., Beitragskategorie (Select aus `fee_categories`, leer möglich), Beitrittsdatum

Labels über die Modul-Sprachdateien (`members::admin`, de + en). Label für `nuliga_id`: „Nuliga-Nr.".

## 3. Validierung (`MemberController` store/update)

Neue Regeln:

- `street` nullable string max:255; `zip` nullable string max:10; `city` nullable string max:128
- `gender` nullable, `in:m,w,d`
- `nuliga_id` nullable integer min:1, unique (beim Update mit Ausnahme des eigenen Datensatzes)
- `fee_category_id` nullable exists:fee_categories,id
- `address`-Regel entfällt.

## 4. CSV-Import (`ImportMembersCommand`)

- `Strasse`/`PLZ`/`Ort` werden getrennt in `street`/`zip`/`city` übernommen (`buildAddress()` entfällt).
- `Geschlecht` → `gender` (normalisiert auf Kleinbuchstaben, nur m/w/d, sonst null).
- `ID-Nr` → `nuliga_id` (numerisch, sonst null).
- `Mitgliedsart` setzt zusätzlich zur Vorschreibung die Standard-Kategorie `fee_category_id` am Mitglied.
- Hinweiszeile „Nicht importiert" reduziert sich auf: Alter, Reservierungssystem, Bemerkung.

## 5. Vorschreibungen (`dues/create`)

Beim manuellen Anlegen wird die Beitragskategorie aus dem gewählten Mitglied vorbefüllt:
Mitglieder-Options tragen `data-fee-category`, ein kleines Inline-Skript setzt beim Wechsel
des Mitglieds das Kategorie-Select. Der Wert bleibt pro Vorschreibung überschreibbar.

## 6. Mitgliederliste (`members/index`)

Neue Spalten:

- **Beitragskategorie** — Name der Standard-Kategorie, leer wenn keine gesetzt.
  Eager Loading (`with('feeCategory')`) im Index.
- **Alter** — berechnet aus `birthdate` (`$member->birthdate?->age`), leer wenn kein
  Geburtsdatum. Keine neue Spalte in der DB, Accessor `age()` am Model.

Neue Filterleiste oberhalb der Tabelle (GET-Formular, kombinierbar mit dem
bestehenden Status-Filter aktiv/ausgetreten):

- **Namenssuche** — Textfeld `search`; filtert `firstname` ODER `lastname`
  mit `LIKE %…%` (Groß-/Kleinschreibung egal).
- **Beitragskategorie** — Select `fee_category` mit allen Kategorien plus
  „Alle"; filtert auf `fee_category_id`.

Die Filter bleiben in der URL erhalten (Query-Parameter), damit Links teilbar sind.

## 7. Tests

- Import-Test: Assertions für `street`/`zip`/`city`, `gender`, `nuliga_id`, `fee_category_id`.
- Mitglieder-CRUD-Tests: neue Felder in store/update, Validierung (gender in-Liste, nuliga_id unique).
- Migrations-Split: Test für das Address-Parsing (inkl. nicht parsebarer Wert → alles in `street`).
- Dues-Formular: Vorbefüllung der Kategorie (data-Attribut vorhanden).
- Mitgliederliste: Namenssuche (Vor- und Nachname), Kategorie-Filter,
  Alter-Berechnung, Kombination mit Status-Filter.

## Nicht im Scope

- Kein Pflichtfeld-Ausbau (alle neuen Felder optional).
- Keine Änderung am Export, außer er bricht durch den Wegfall von `address` (dann minimal anpassen).
- Kein rückwirkendes Umkategorisieren bestehender Vorschreibungen.
