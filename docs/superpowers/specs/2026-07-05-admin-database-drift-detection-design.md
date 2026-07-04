# /admin/database: Schema-Drift-Erkennung

## Problem

Der bestehende Schema-Check auf `/admin/database` (siehe [DatabaseSchemaChecker](../../../app/Services/DatabaseSchemaChecker.php)) erkennt nur, ob eine Tabelle fehlt oder ob eine erwartete Spalte fehlt. Er ist blind für die Umkehrung:

- **Unerwartete Extra-Spalten** (jemand hat manuell `ALTER TABLE ADD` gemacht, oder eine alte Legacy-Spalte wurde nie im Manifest erfasst).
- **Typabweichungen** einer vorhandenen Spalte (z.B. `varchar(64)` statt `varchar(128)`, `nullable` vertauscht, falscher Default).
- **Fehlende oder unerwartete Indizes** (inkl. zusammengesetzter Unique-Constraints wie bei `fee_category_rates`, `member_dues`, `payment_dues`).

Dies ist Stufe 1 von drei geplanten Ausbaustufen (siehe [2026-07-04-admin-database-repair-design.md](2026-07-04-admin-database-repair-design.md), Abschnitt „Nicht im Scope"). Stufe 1 ist **rein lesend** — keine Reparatur, keine Schreibzugriffe auf reale Tabellen. Reparatur von Drift-Befunden folgt in Stufe 3, nachdem Stufe 2 (Backup) ein Sicherheitsnetz geschaffen hat.

## Design

### Kernidee: Probe-Tabellen statt hartkodierter Typ-Strings

Die Tests laufen gegen SQLite (`phpunit.xml`), Produktion gegen MySQL. `Schema::getColumns()` liefert je nach Treiber unterschiedliche Typ-Strings (z.B. `varchar(128)` vs. `varchar`). Hartkodierte erwartete Typen im Manifest würden also nur für einen Treiber stimmen und in Tests falsch anschlagen.

Stattdessen wird der **Soll-Zustand pro Tabelle live erzeugt**: Eine temporäre „Probe"-Tabelle wird mit denselben Blueprint-Closures angelegt, die auch im bestehenden `columnDefinitions()`-Manifest stehen ([DatabaseSchemaChecker.php:20-...](../../../app/Services/DatabaseSchemaChecker.php)). Anschließend werden `Schema::getColumns()` und `Schema::getIndexes()` der Probe-Tabelle mit denen der echten Tabelle verglichen — beide über denselben Connection-Treiber, also immer im gleichen Format. Die Probe-Tabelle wird danach sofort gelöscht.

Das ist automatisch treiberkorrekt (SQLite in Tests, MySQL in Produktion) und dupliziert kein Wissen über Laravel-Grammatik.

### Neue Manifest-Ergänzung: zusammengesetzte Constraints

Drei Tabellen haben tabellenweite Unique-Constraints, die nicht in den Spalten-Closures stecken (`fee_category_rates`, `member_dues`, `payment_dues`). Neue private Methode `tableLevelConstraints(): array<string, Closure(Blueprint): mixed>` in `DatabaseSchemaChecker`, die diese drei Einträge liefert, z.B.:

```php
'fee_category_rates' => fn (Blueprint $t) => $t->unique(['fee_category_id', 'fiscal_year_id']),
```

Wird sowohl beim Aufbau der Probe-Tabelle als auch — implizit über den Indexvergleich der Probe — bei der Drift-Prüfung verwendet.

### Vergleichslogik

Neue Methode `driftReport(): array<int, array{table: string, kind: string, description: string}>`:

1. **Nur für Tabellen, die existieren** (fehlende Tabellen werden weiterhin ausschließlich von `checkTables()` gemeldet — kein Doppel-Reporting).
2. Probe-Tabelle `__drift_probe__{table}` anlegen (vorher `dropIfExists`, um Reste eines abgebrochenen vorherigen Laufs zu entfernen); Closures aus `columnDefinitions()[$table]` und ggf. `tableLevelConstraints()[$table]` anwenden.
3. `Schema::getColumns()` und `Schema::getIndexes()` von Probe- und echter Tabelle holen.
4. **Extra-Spalten:** Namen in der echten Tabelle, die nicht in der Probe vorkommen → `kind: 'extra_column'`.
5. **Typabweichung:** für Spalten, die in beiden vorkommen, Vergleich von `type` (voller Typ-String), `nullable`, `default` → bei Abweichung `kind: 'type_mismatch'`, Beschreibung zeigt Soll- und Ist-Wert.
6. **Index-Abgleich:** Indizes beider Seiten auf ein normalisiertes Signal reduzieren (`sortierte Spalten . '|' . unique-Flag`), `primary`-Indizes dabei ausgeschlossen (Namensdifferenz durch unterschiedliche Tabellennamen macht Namensvergleich unbrauchbar). Fehlt ein Soll-Index in der echten Tabelle → `kind: 'missing_index'`. Ist ein Index in der echten Tabelle, der nicht im Soll vorkommt → `kind: 'extra_index'`.
7. Probe-Tabelle in einem `finally`-Block löschen — auch wenn beim Anlegen (z.B. fehlende Fremdtabelle für `constrained()`) eine Exception auftritt. Schlägt der Aufbau der Probe-Tabelle fehl, wird das für diese Tabelle als ein Eintrag `kind: 'drift_check_failed'` mit der Fehlermeldung gemeldet, statt die ganze Seite abstürzen zu lassen — die übrigen Tabellen werden trotzdem geprüft.

### Anzeige auf /admin/database

Neuer Abschnitt „Schema-Abweichungen" unterhalb der bestehenden Tabellen-Übersicht, nur befüllt wenn `driftReport()` nicht leer ist (sonst ein Hinweistext „Keine Abweichungen gefunden"). Eine flache Liste, gruppiert nach Tabelle:

```
bs_users
  ⚠ Spalte last_ip: erwartet varchar(64) nullable, ist varchar(32) not null
  ⚠ unerwartete Spalte legacy_note
members
  ⚠ fehlender Index (member_number) unique
```

Reine Anzeige in Stufe 1 — keine Buttons. Die Reparatur-Buttons aus Stufe 3 docken hier später an (`kind` bestimmt später, welche Reparatur-Aktion angeboten wird).

### Performance-Überlegung

Pro Seitenaufruf werden bis zu 22 Probe-Tabellen angelegt und wieder gelöscht (nur für existierende Tabellen, i.d.R. alle). Das ist eine Admin-Seite mit seltenen Aufrufen auf einer kleinen Vereins-Datenbank — vertretbar. Kein Caching in Stufe 1; falls sich das in der Praxis als spürbar langsam erweist, kann später ein kurzlebiger Cache ergänzt werden (nicht Teil dieser Spec).

## Tests

**Unit (`DatabaseSchemaCheckerTest`):**
- gesundes Schema → `driftReport()` liefert `[]`.
- Extra-Spalte manuell hinzugefügt → wird als `extra_column` gemeldet, mit korrektem Tabellen-/Spaltennamen.
- Spalte mit abweichendem Typ (z.B. `bs_users.alias` auf `string(32)` statt `128` reduziert) → `type_mismatch` mit Soll/Ist in der Beschreibung.
- Spalte mit abweichendem `nullable` → `type_mismatch`.
- Fehlender Index (z.B. `bs_users_meta` ohne `key`-Index) → `missing_index`.
- Unerwarteter Index (manuell hinzugefügt) → `extra_index`.
- Fehlender zusammengesetzter Unique-Index bei `member_dues` → `missing_index`.
- Fehlende Tabelle wird NICHT zusätzlich in `driftReport()` gemeldet (nur in `checkTables()`).
- Probe-Tabellen existieren nach jedem Aufruf nicht mehr (`Schema::hasTable('__drift_probe__bs_users')` ist `false`).

**Feature (`DatabaseControllerTest`):**
- Admin sieht den „Schema-Abweichungen"-Abschnitt mit „Keine Abweichungen gefunden" im gesunden Zustand.
- Bei künstlich erzeugter Extra-Spalte erscheint der entsprechende Text auf der Seite.

## Nicht im Scope (folgt in Stufe 2/3)

- Reparatur von Drift-Befunden (Spalten ändern/löschen, Indizes anlegen/löschen).
- Backup/Export/Restore.
- Datenmigration (vom Nutzer explizit nicht benötigt).
