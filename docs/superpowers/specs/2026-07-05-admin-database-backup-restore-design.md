# /admin/database: Backup/Export/Restore

## Problem

`/admin/database` kann Schema-Zustand prüfen ([2026-07-04-admin-database-repair-design.md](2026-07-04-admin-database-repair-design.md)) und Drift erkennen ([2026-07-05-admin-database-drift-detection-design.md](2026-07-05-admin-database-drift-detection-design.md)), aber es gibt kein Sicherheitsnetz: Auf one.com (Shared Hosting, kein SSH, kein `mysqldump`) existiert keine Möglichkeit, vor einer riskanten Aktion (Migration, Reparatur, manuelle Änderung) einen Datenbank-Snapshot zu ziehen oder im Fehlerfall zurückzuspielen. Dies ist Stufe 2 von drei geplanten Ausbaustufen (siehe „Nicht im Scope" in der Repair-Spec). Stufe 3 (Reparatur bestehender Spalten/Indizes) baut auf diesem Sicherheitsnetz auf.

## Design

### Neuer Service: `DatabaseBackupService`

Parallel zu `DatabaseSchemaChecker`, eigene Verantwortung: Dumps erzeugen, auflisten, löschen, wiederherstellen. Speicherort: `storage/app/backups/` (Laravel-Standard, nicht über den Webserver erreichbar).

### Treiberunabhängige Dump-Erzeugung

Wie beim Drift-Checker wird nicht MySQL-spezifisch hartkodiert, sondern Laravels eigene Introspektion genutzt, damit derselbe Code unter SQLite (Tests) und MySQL (Produktion) läuft:

- **Tabellenliste:** `Schema::getTableListing()` — alle Tabellen der aktuellen DB, unabhängig vom Checker-Manifest (Backup soll alles sichern, nicht nur bekannte Tabellen).
- **Struktur je Tabelle:** je Treiber die native Quelle lesen — `SHOW CREATE TABLE` (MySQL) bzw. `SELECT sql FROM sqlite_master WHERE type='table' AND name=?` (SQLite). Mehrzeilige Formatierung wird auf eine Zeile reduziert (Whitespace ist in DDL irrelevant).
- **Daten je Tabelle:** `DB::table($table)->get()`, Spalten über `Schema::getColumnListing($table)`, Werte in Batches von 500 Zeilen zu `INSERT INTO` Statements zusammengefasst, jeder Wert über `PDO::quote()` escaped.

### Dump-Format

Eine gzip-komprimierte Textdatei, **ein SQL-Statement pro Zeile** (keine eingebetteten Zeilenumbrüche in einem Statement, dadurch ist Zeilen-Split beim Restore ohne SQL-Parser sicher — `PDO::quote()` escaped rohe Newlines in Werten):

```
DROP TABLE IF EXISTS `bs_users`;
CREATE TABLE `bs_users` (...) ...;
INSERT INTO `bs_users` (`uid`,`alias`,...) VALUES (...), (...), ...;
DROP TABLE IF EXISTS `members`;
CREATE TABLE `members` (...);
...
```

Kein `SET FOREIGN_KEY_CHECKS` in der Datei selbst — das übernimmt `restore()` über Laravels treiberneutrale `Schema::disableForeignKeyConstraints()` / `enableForeignKeyConstraints()` um den gesamten Statement-Lauf.

Dateiname: `backup-<Ymd_His>.sql.gz` (manuell) bzw. `backup-<Ymd_His>-prerestore.sql.gz` (automatisches Sicherheits-Backup vor einem Restore).

### Service-Schnittstelle

```
list(): array<{filename: string, sizeBytes: int, createdAt: Carbon}>   // neueste zuerst
create(?string $suffix = null): string                                  // gibt Dateinamen zurück
delete(string $filename): void
restore(string $filename): array<int, string>                           // Liste ausgeführter Statements/Fehler-Kontext
```

`create()` prunt nach jedem Lauf: mehr als 10 Backups → älteste löschen (ein gemeinsamer Pool für manuelle und Pre-Restore-Backups, keine getrennte Kontingentierung).

`restore(string $filename)`:
1. Legt zuerst automatisch ein Sicherheits-Backup an (`create('prerestore')`).
2. Liest die Ziel-Datei, entpackt, führt Zeile für Zeile via `DB::unprepared()` aus, umrahmt von `Schema::disableForeignKeyConstraints()`/`enableForeignKeyConstraints()`.
3. Bricht bei der ersten fehlschlagenden Zeile ab (kein Silent-Continue) und wirft mit Kontext, welches Statement fehlschlug — der Aufrufer (Controller) fängt das ab und verweist in der Fehlermeldung explizit auf den Namen des soeben erstellten Sicherheits-Backups.

### Controller & Routen

Neue Actions in `DatabaseController`: `createBackup()`, `downloadBackup(string $filename)`, `deleteBackup(string $filename)`, `restoreBackup(Request $request, string $filename)`. Routen mit striktem Pattern-Constraint, bevor irgendein Dateisystemzugriff passiert:

```
POST admin/database/backups                         → createBackup
GET  admin/database/backups/{filename}/download      → downloadBackup
POST admin/database/backups/{filename}/delete         → deleteBackup
POST admin/database/backups/{filename}/restore        → restoreBackup
```

Route-Parameter `{filename}` mit `->where('filename', '^backup-\d{8}_\d{6}(-prerestore)?\.sql\.gz$')` — ungültige Namen matchen die Route gar nicht (404), kein Path-Traversal über den Parameter möglich.

### UI

Neues Panel „Backups" unterhalb „Schema-Abweichungen": Tabelle mit Dateiname, Größe, Zeitstempel, drei Aktionen pro Zeile (Herunterladen / Löschen / Wiederherstellen) plus einem „Backup erstellen"-Button über der Liste.

- **Erstellen:** kein Bestätigungsdialog (rein additiv).
- **Löschen:** leichte JS-`confirm()`-Bestätigung, wie die bestehenden Reparieren-Buttons.
- **Wiederherstellen:** getippte Bestätigung analog zum Migrate-Button, eigener Bestätigungswert `RESTORE` (überschreibt alle Live-Daten — das folgenreichste Aktion der ganzen Seite).
- **Herunterladen:** einfacher Link, `Storage::disk('local')->download(...)`.

### Fehlerbehandlung

- Unbekannte/ungültige Dateinamen: bereits durch das Route-Pattern ausgeschlossen (404).
- `restore()`-Fehler: `report()`, Redirect mit konkreter Fehlermeldung inkl. Name des automatisch erstellten Sicherheits-Backups zur Wiederherstellung.
- `create()`-Fehler (z.B. Storage voll/nicht schreibbar): `report()`, Redirect mit Fehlermeldung, keine Halb-Datei zurücklassen (Schreiben in eine temporäre Datei, erst bei Erfolg umbenennen — oder bei Exception die unvollständige Datei löschen).

## Tests

**Unit (`DatabaseBackupServiceTest`, gegen SQLite wie der Rest der Suite):**
- `create()` erzeugt eine Datei in `storage/app/backups/`, gzip-lesbar, mit den erwarteten Tabellen-Statements.
- `list()` liefert neueste zuerst, korrekte Größen.
- `delete()` entfernt die Datei; erneutes `delete()` einer nicht existierenden Datei ist ein No-op (kein Fehler).
- Aufbewahrung: nach dem 11. `create()`-Aufruf existieren nur noch 10 Dateien, die älteste ist weg.
- **Round-Trip:** Daten in eine Test-Tabelle schreiben, `create()`, Tabelle danach leeren/droppen, `restore()`, Daten sind wieder da (inkl. Original-Schema).
- `restore()` legt vorher automatisch ein `-prerestore`-Backup an.
- `restore()` einer Datei mit absichtlich kaputtem SQL wirft mit einer Meldung, die den Namen des soeben erstellten Sicherheits-Backups enthält.

**Feature (`DatabaseControllerTest`):**
- Admin kann Backup erstellen, Liste erscheint auf der Seite.
- Admin kann ein Backup herunterladen (Content-Type/Disposition, Inhalt gzip-gültig).
- Admin kann ein Backup löschen.
- Admin muss `RESTORE` eintippen, sonst kein Restore.
- Admin kann mit korrekter Bestätigung wiederherstellen.
- Normales Mitglied kann keine der vier Aktionen ausführen (403).
- Ungültiger Dateiname in der URL → 404.

## Nicht im Scope (folgt in Stufe 3)

- Reparatur von Drift-Befunden aus Stufe 1 (Spalten ändern/löschen, Indizes anlegen/löschen) — jetzt freigeschaltet durch das hier gebaute Sicherheitsnetz.
- Hochladen fremder SQL-Dateien für Restore (bewusst nicht vorgesehen — nur serverseitige Backups aus der eigenen Liste).
- Datenmigration (vom Nutzer explizit nicht benötigt).
