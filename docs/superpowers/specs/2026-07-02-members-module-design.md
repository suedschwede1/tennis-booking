# Mitgliederverwaltung als generisches Modul-System

## Problem

Es gibt keine Mitgliederverwaltung im System — Vereinsmitglieder, Mitgliedsbeiträge (unterschiedliche Kategorien: Vollmitglied, unterstützend, Kind, Familienmitgliedschaft, Anschlussmitglied) und Fiskaljahre werden aktuell außerhalb der App verwaltet. Mitglieder sind bewusst **nicht** mit den Buchungssystem-Benutzern (`bs_users`) verknüpft — es ist eine eigene fachliche Domäne.

Gleichzeitig gibt es im Repo noch kein Muster für in sich geschlossene, unabhängig testbare Module — alle bisherige Fachlichkeit liegt flach unter `app/Http/Controllers`, `app/Models`, `database/migrations`. Die Mitgliederverwaltung soll das erste Modul eines generischen Modul-Systems werden, damit künftige fachfremde Bereiche nach demselben Muster entstehen können, mit eigener, schneller Testsuite statt der gesamten Booking-Suite (37+ Dateien).

## Design

### 1. Generisches Modul-System

Neuer Namespace `App\Modules\<ModuleName>` unter `app/Modules/<ModuleName>/`:

```
app/Modules/<ModuleName>/
  Http/Controllers/
  Models/
  Requests/
  Database/Migrations/
  <ModuleName>ServiceProvider.php
  routes.php
```

- `<ModuleName>ServiceProvider extends ServiceProvider`: lädt `routes.php` (`loadRoutesFrom`), Migrations (`loadMigrationsFrom`), optional Views/Übersetzungen. Wird in `bootstrap/providers.php` registriert.
- Migrations eines Moduls liegen **nicht** in `database/migrations/`, sondern in `app/Modules/<ModuleName>/Database/Migrations/` und werden ausschließlich über den Modul-ServiceProvider geladen. `php artisan migrate` erfasst sie automatisch, da Laravel alle über `loadMigrationsFrom` registrierten Pfade mit einschließt.
- Modul-Tests liegen unter `tests/Feature/Modules/<ModuleName>/` bzw. `tests/Unit/Modules/<ModuleName>/`.
- `phpunit.xml` bekommt pro Modul einen eigenen `<testsuite name="<ModuleName>">`-Eintrag, sodass `php artisan test --testsuite=<ModuleName>` nur die Modul-Tests laufen lässt (Restlaufzeit der Booking-Suite bleibt unangetastet).

**Artisan-Scaffolding:** `php artisan make:module <Name>` (neuer Command `app/Console/Commands/MakeModuleCommand.php`) legt an:
- die obige Ordnerstruktur inklusive leerem `<Name>ServiceProvider` und leerer `routes.php`
- einen leeren Test-Ordner `tests/Feature/Modules/<Name>/`
- gibt am Ende eine Erinnerung aus, den `<Name>ServiceProvider` in `bootstrap/providers.php` sowie den `<testsuite>`-Block in `phpunit.xml` manuell zu ergänzen (kein automatisches XML-Editing, um `phpunit.xml` nicht kaputt zu schreiben)

### 2. Modul „Members" — Datenmodell

Alle Tabellen unter `App\Modules\Members\Models`, Migrations unter `app/Modules/Members/Database/Migrations/`:

```
members
  id, member_number (unique), firstname, lastname, birthdate (nullable), email (nullable),
  phone (nullable), address (nullable),
  joined_at, left_at (nullable), created_at, updated_at
  -- member_number wird beim Anlegen automatisch auf (aktuelles Maximum + 1) vorbelegt,
     ist aber im Formular editierbar (z.B. um Nummern aus einer Alt-Liste zu übernehmen);
     muss eindeutig bleiben

fiscal_years
  id, name (z.B. "2025/26"), starts_on, ends_on

fee_categories
  id, name (z.B. "Vollmitglied", "unterstützend", "Kind",
            "Familienmitgliedschaft", "Anschlussmitglied")

fee_category_rates
  id, fee_category_id, fiscal_year_id, amount

member_dues
  id, member_id, fiscal_year_id, fee_category_id, amount, created_at
  -- amount wird beim Anlegen aus fee_category_rates kopiert, damit spätere
     Satzänderungen bestehende Jahre nicht rückwirkend verändern

payments
  id, member_id, amount, paid_at, note (nullable), created_at

payment_dues (pivot)
  payment_id, member_due_id
```

**Ableitungen (keine eigenen Spalten, als Model-Accessor/Query-Scope):**
- Ein `member_due` gilt als bezahlt, wenn ihm über `payment_dues` mindestens eine `payment` zugeordnet ist. Eine Zahlung deckt beim Erfassen genau die Beiträge ab, die der Admin ihr zuordnet (kein automatisches Splitten eines Zahlbetrags über mehrere Fälligkeiten mit Restbetrag).
- „Bezahlt bis" eines Mitglieds = Enddatum (`ends_on`) des jüngsten Fiskaljahres, dessen `member_due` für dieses Mitglied als bezahlt gilt.

**Familienmitgliedschaft / Anschlussmitglied:** reine `fee_categories`-Einträge ohne technische Verknüpfung zwischen Mitgliedern. Bei „Familienmitgliedschaft" bucht ein Mitglied (das der Familie zugeordnete Beitrags-Konto) den vollen Familienbeitrag; andere Familienmitglieder können als eigene `members`-Datensätze (für Kontaktdaten) existieren, erhalten aber in dem Jahr keinen eigenen `member_dues`-Eintrag. Keine `families`-Tabelle.

### 3. Berechtigung

Neues Privileg `admin.members`, gleiches Muster wie `admin.user`/`admin.config` in `app/Models/User.php`:
- `status = admin` → automatisch erlaubt
- `status = assist` mit `bs_users_meta`-Eintrag `allow.admin.members = 'true'` → erlaubt
- alle anderen (inkl. normale Mitglieder-Buchungsnutzer) → kein Zugriff

Kein Self-Service-Zugang für Mitglieder selbst — die Verwaltung ist ausschließlich für Admins/Vorstand.

### 4. Routen & Controller

Unter `App\Modules\Members\Http\Controllers`, Prefix `/admin/members`, Middleware `auth` + `can:admin.members`, definiert in `app/Modules/Members/routes.php`:

- `MemberController` — Liste (Filter: aktiv/ausgetreten, Beitragskategorie, bezahlt/offen), Anlegen/Bearbeiten/Austragen (setzt `left_at`)
- `FiscalYearController` — Anlegen/Bearbeiten (Name, Start-, Enddatum)
- `FeeCategoryController` — Kategorien anlegen/umbenennen; je Fiskaljahr Betrag festlegen (`fee_category_rates`)
- `MemberDueController` — Bulk-Generierung offener `member_dues` für ein gewähltes Fiskaljahr über alle aktiven Mitglieder (Kategorie wird vom letzten `member_due` des Mitglieds übernommen; Mitglieder ohne Vorjahres-Kategorie werden in der Liste zur manuellen Zuordnung markiert)
- `PaymentController` — Zahlung erfassen (Betrag, Datum, Notiz, Auswahl der abgedeckten offenen `member_dues`)
- `MemberExportController` — CSV-Export der Mitgliederliste bzw. offener/bezahlter Beiträge
- `DashboardController` — Kacheln: Anzahl aktiver Mitglieder, offene Beiträge (Anzahl + Summe €), Mitglieder pro Kategorie — analog zur bestehenden `Admin\StatisticsController`

## Tests

- `tests/Feature/Modules/Members/` deckt jeden Controller ab (CRUD, Berechtigungs-Grenzfälle: kein Zugriff ohne `admin.members`, Bulk-Generierung erzeugt keine Duplikate für bereits vorhandene `member_dues`, CSV-Export-Inhalt, Zahlung deckt mehrere Fiskaljahre ab).
- `tests/Unit/Modules/Members/` für Model-Ableitungen: „ist Beitrag bezahlt", „bezahlt bis".
- Eigener `<testsuite name="Members">`-Eintrag in `phpunit.xml`, lauffähig isoliert via `php artisan test --testsuite=Members`, ohne Abhängigkeit von Booking-Fixtures.
- `make:module`-Command selbst bekommt einen Test unter `tests/Feature/Console/MakeModuleCommandTest.php` (erzeugt erwartete Dateien/Ordner für einen Test-Modulnamen in einem temporären Verzeichnis).
