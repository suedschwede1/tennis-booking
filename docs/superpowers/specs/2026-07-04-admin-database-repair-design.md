# /admin/database: Fehlerausgabe & Tabellen-Reparatur

## Problem

Die Seite `/admin/database` ist auf one.com (kein SSH, kein Log-Zugriff) das einzige Werkzeug, um Migrationen in Produktion auszuführen und Schema-Drift zu erkennen. Zwei Lücken:

1. **Fehler sind stumm.** Schlägt `migrate` fehl, erscheint nur der generische Text `booking.admin.database.migrate_failed`. Der Artisan-Output und die konkrete Exception-Meldung sind nirgends sichtbar — in Produktion ein Blindflug.
2. **Schema-Drift ist erkennbar, aber nicht behebbar.** Der Schema-Check zeigt fehlende Tabellen und fehlende Spalten, bietet aber keine Korrektur an. Fehlt eine Tabelle, deren Migration als „ran" markiert ist, oder fehlen einzelne Spalten auf einer existierenden Tabelle, hilft auch der Migrate-Button nicht.

## Design

### 1. Fehler- und Output-Anzeige

- `DatabaseController::migrate()` fängt nach `Artisan::call('migrate', ['--force' => true])` den Output über `Artisan::output()` ein und flasht ihn in die Session (`output`).
- Im Fehlerfall (Throwable) wird zusätzlich `$e->getMessage()` als konkrete Fehlermeldung geflasht — statt nur des generischen Textes — plus der bis dahin erzeugte Artisan-Output.
- Die View zeigt geflashten Output als einklappbaren Monospace-Block (`<details>`/`<pre>`) unter der Erfolgs-/Fehlermeldung.
- Die Reparatur-Aktion (Punkt 2) nutzt dieselbe Anzeige-Mechanik: durchgeführte Aktionen bzw. Fehlermeldung werden geflasht und identisch dargestellt.

### 2. Tabellen-Reparatur

Auf Problemzeilen im Schema-Check (Tabelle fehlt oder Spalten fehlen) erscheint ein **„Reparieren"-Button** pro Tabelle. Ablauf mit Bestätigung analog zum Migrate-Button (Confirm-Dialog; POST mit CSRF).

Zwei Fälle, zwei Strategien:

**Fall A — Tabelle fehlt komplett:**
- Der Service sucht die zugehörige Create-Migration (Namenskonvention `*_create_{table}_table`) über alle registrierten Migrationspfade (`database/migrations` + `$migrator->paths()`).
- Ist die Migration als „ran" markiert, obwohl die Tabelle fehlt, wird ihr Eintrag aus der `migrations`-Tabelle entfernt und die Migration erneut ausgeführt (`up()` via Migrator, damit der Eintrag danach wieder korrekt gesetzt ist).
- Ist sie ohnehin pending, entspricht die Reparatur dem normalen Migrationslauf für genau diese Datei.
- Keine Duplizierung der Schema-Definition — die Migration bleibt die Quelle der Wahrheit.

**Fall B — Tabelle existiert, Spalten fehlen:**
- `Schema::table()` mit `ADD COLUMN` für genau die fehlenden Spalten.
- Dafür bekommt der `DatabaseSchemaChecker` ein Definitions-Manifest: pro Tabelle und Spalte eine Blueprint-Closure (Typ, Länge, nullable, default), abgeleitet aus den Migrationen. `EXPECTED_COLUMNS` wird aus den Schlüsseln dieses Manifests abgeleitet, damit beide nicht auseinanderlaufen können.
- Einmaliger Fleißaufwand für die 22 Tabellen; nur so kennt die Reparatur die Spaltentypen, ohne Create-Migrationen auf existierende Tabellen anwenden zu müssen.

**Sicherheitsprinzip:** Die Reparatur legt ausschließlich Fehlendes an (Tabellen, Spalten). Sie löscht nichts, ändert keine bestehenden Spalten, benennt nichts um. Unerwartete Extra-Spalten bleiben unangetastet.

**Vorschau:** Jede Problemzeile zeigt vor der Reparatur an, was passieren würde („legt Tabelle an" / „ergänzt Spalten: x, y").

### Komponenten

- `DatabaseSchemaChecker` (erweitert):
  - Spalten-Definitions-Manifest (Blueprint-Closures) als Quelle für `EXPECTED_COLUMNS`.
  - `repairTable(string $table): array` — führt Fall A/B aus, gibt Liste der durchgeführten Aktionen zurück; No-op mit leerer Liste, wenn die Tabelle gesund ist; Exception bei unbekanntem Tabellennamen.
- `DatabaseController::repair(Request, string $table)` — validiert Tabellennamen gegen das Manifest (kein beliebiger Input), ruft `repairTable()` auf, flasht Aktionen/Fehler.
- Route: `POST admin/database/repair/{table}`, gleiche Admin-Middleware wie `database/migrate`.
- View: Reparieren-Button + Vorschautext auf Problemzeilen, Output-Block (gemeinsam mit Migrate).

### Fehlerbehandlung

- Unbekannter Tabellenname → 404/Validation-Fehler, kein DB-Zugriff.
- Throwable während der Reparatur → `report()`, Redirect mit konkreter Fehlermeldung + bereits durchgeführten Aktionen.
- Reparatur einer gesunden Tabelle → Hinweis „nichts zu tun", kein Fehler.

## Tests

- **Unit (`DatabaseSchemaCheckerTest`):** fehlende Tabelle wird über ihre Migration wieder angelegt (inkl. Korrektur des `migrations`-Eintrags); fehlende Spalte wird ergänzt und ist danach im Schema-Check grün; gesunde Tabelle → No-op; unbekannte Tabelle → Exception; Manifest-Konsistenz (`EXPECTED_COLUMNS` == Manifest-Schlüssel).
- **Feature (`DatabaseControllerTest`):** Admin kann reparieren, normales Mitglied nicht (403); unbekannte Tabelle → 404; Erfolgs- und Fehlermeldung inkl. Output-Block erscheinen auf der Seite; Migrate-Fehlerfall zeigt konkrete Exception-Meldung.

## Nicht im Scope

- Löschen/Ändern bestehender Spalten, Index-Reparatur, Datenmigration.
- Drift-Erkennung für unerwartete Extra-Spalten.
- Backup/Export vor Reparatur.
