# Admin-Bereich — Design (2026-06-26)

Status: **Approved** (Scope + Architektur vom Nutzer freigegeben)

## Ziel

Vollständige Admin-Funktionalität für das tcbewegung-Buchungssystem (Laravel-Neubau), originalgetreu zum ZF2-Altsystem, gebaut auf dem bereits vorhandenen `User::can()`-Berechtigungsmodell (status `admin`/`assist`/`enabled` + `bs_users_meta` `allow.*`-Flags). Siehe Memory `booking-permission-model` und `real-db-schema`.

## Scope (freigegeben)

Granulare Zugriffssteuerung über `can()`. Voll:
- **Benutzerverwaltung** (`admin.user`): CRUD inkl. assist-Rechte-Toggling, Passwort setzen/zurücksetzen, Soft-Delete.
- **Veranstaltungs-/Sperrenverwaltung** (`admin.event`): CRUD.
- **Buchungsverwaltung** (`admin.booking`): zentrale Liste aller Buchungen + Storno.
- **Konfiguration** (`admin.config`): kuratiertes Formular über `bs_options`.
- **Kalender-Adminrechte**: fremde Buchungen stornieren (`calendar.cancel-single-bookings`/`…subscription…`), für Mitglied buchen, readonly-Plätze buchen (`calendar.create-single-bookings`), Namen sehen (`calendar.see-data`), Vergangenheit sehen (`calendar.see-past`).

Out of scope (vorerst): Preis-/Produkt-/Coupon-Verwaltung, Abo-Erstellung im Admin, Mehrsprachigkeit der Config-Felder (nur Default-Locale editierbar).

## 1. Zugriffssteuerung

- **`Gate::before`** (in `AppServiceProvider::boot`): delegiert jede Gate-Prüfung an `User::can()`:
  ```php
  Gate::before(fn (User $user, string $ability) => $user->can($ability) ? true : null);
  ```
  Damit funktionieren Laravels `can:`-Middleware, `@can`-Blade-Direktive und `Gate`-Facade konsistent mit admin/assist/`allow.*`. (`null` statt `false`, damit künftige echte Policies nicht blockiert werden.)
- **Routen**: Gruppe `prefix('admin')->name('admin.')->middleware('auth')`, je Bereich zusätzlich `can:<privileg>`.
- **Navigation**: Admin-Link im Hauptlayout nur unter `@can('admin.see-menu')`.

## 2. Routen

```
GET  /admin                         admin.dashboard      (can:admin.see-menu)
Resource /admin/users               admin.users.*        (can:admin.user)
POST /admin/users/{user}/password   admin.users.password (can:admin.user)
Resource /admin/events              admin.events.*       (can:admin.event)
GET    /admin/bookings              admin.bookings.index (can:admin.booking)
GET    /admin/bookings/{booking}    admin.bookings.show  (can:admin.booking)
DELETE /admin/bookings/{booking}    admin.bookings.destroy (can:admin.booking)
GET  /admin/config                  admin.config.edit    (can:admin.config)
PUT  /admin/config                  admin.config.update  (can:admin.config)
```

## 3. Controller (`App\Http\Controllers\Admin\*`)

- **DashboardController@index** — Übersicht/Kacheln zu den Bereichen (nur sichtbare je nach `can()`).
- **UserController** — `index, create, store, edit, update, destroy` + `password(User)`.
  - Felder: `alias`, `email`, `status` (admin/assist/enabled/disabled), Profil-Meta (firstname, lastname, phone, …) in `bs_users_meta`.
  - **assist-Rechte**: Checkbox je Privileg aus der festen Privilegienliste; speichern = `bs_users_meta` `allow.<priv>='true'` setzen bzw. Zeile löschen.
  - **Passwort**: beim Anlegen Initialpasswort (Eingabe oder generiert); `password()`-Action setzt neues `pw` (bcrypt).
  - **Soft-Delete**: `destroy` setzt `status='deleted'` (Buchungshistorie bleibt erhalten); gelöschte Nutzer aus Listen ausgeblendet.
- **EventController** — `index, create, store, edit, update, destroy`.
  - Felder: `sid` (konkreter Platz oder „alle" = null), `datetime_start`, `datetime_end`, `status`, optionaler Name → `bs_events_meta` key `name`.
- **BookingController** — `index` (Filter: Datum, Platz, Mitglied; Standard: ab heute), `show`, `destroy` (Storno = `status='cancelled'`).
- **OptionController** — `edit`/`update`: kuratiertes Formular für: Vereinsname (`client.name.full`), Kontakt-E-Mail (`client.contact.email`), Kalender-Tage (`service.calendar.days`), Registrierung (`service.user.registration`), Wartungsmodus (`service.maintenance`). Schreibt die Default-Locale-Zeile (`locale IS NULL`).
- **Layout**: `resources/views/layouts/admin.blade.php` mit Seitennavigation; Views unter `resources/views/admin/*`.

## 4. Kalender-Integration (bestehender Frontend-Flow)

- **Storno fremder Buchungen**: `BookingController@destroy` (Frontend) erlaubt, wenn Eigentümer **oder** `can('calendar.cancel-single-bookings')` (bei Abos `…cancel-subscription-bookings`). Sonst 403.
- **Für Mitglied buchen**: Buchungsdialog/`store` zeigt für `can('calendar.create-single-bookings')` ein optionales Mitglieds-Auswahlfeld (`for_uid`). Wenn gesetzt, wird die Buchung diesem `uid` zugeordnet (validiert: existierendes, nicht gelöschtes Mitglied). Ohne Angabe = eigener Account.
- **Namens-Sichtbarkeit (4a, originalgetreu)**: In der Kalenderzelle wird ein fremder Name nur gerendert, wenn `auth()->user()?->can('calendar.see-data')` **oder** Platz-Meta `public_names==='true'`. Eigene Buchung zeigt immer den eigenen Namen. (Ändert bisheriges Verhalten: normale Mitglieder sehen fremde Namen künftig nicht.)
- **Vergangenheit (4b)**: Vergangene Buchungsdetails (Name) nur für `can('calendar.see-past')`/`see-data`-Admins; für andere bleiben vergangene Felder ausgegraut ohne Text.

## 5. Datenmodell-Hinweise

- Privilegienliste als Konstante (z. B. `User::PRIVILEGES`) — Quelle für die assist-Checkboxen und Validierung. Werte = die 13 Privilegien des Altsystems (`admin.*`, `calendar.*`).
- `bs_users_meta`/`bs_events_meta`/`bs_options` nutzen Spalten `key`/`value` (+ `locale` bei events/options).
- Soft-Delete-Konvention: `status='deleted'`; `User::isEnabled()` bleibt false; Auth/Listen filtern.
- Platz-Anzeige nutzt vorhandenes `Square::getDisplayName` (alias → Legacy-Map → name).

## 6. Berechtigungsmatrix (Auszug)

| Aktion | admin | assist mit Flag | enabled |
|---|---|---|---|
| `/admin` sehen | ✓ | `allow.admin.see-menu` | ✗ |
| User verwalten | ✓ | `allow.admin.user` | ✗ |
| Events verwalten | ✓ | `allow.admin.event` | ✗ |
| Buchungsliste/Storno | ✓ | `allow.admin.booking` | ✗ |
| Config | ✓ | `allow.admin.config` | ✗ |
| fremde Buchung stornieren (Kalender) | ✓ | `allow.calendar.cancel-single-bookings` | ✗ |
| fremde Namen sehen | ✓ | `allow.calendar.see-data` | nur eigene |

## 7. Teststrategie (gegen reales Schema, sqlite)

- **Zugriff** je Bereich: admin erlaubt; assist mit Flag erlaubt; assist ohne Flag 403; `enabled` 403; Gast → /login.
- **UserController**: anlegen (inkl. pw-Hash, Profil-Meta), bearbeiten, assist-Flags togglen (Meta-Zeilen entstehen/verschwinden), Passwort-Reset, Soft-Delete (status=deleted, taucht nicht in Liste auf).
- **EventController**: CRUD; Event erscheint/verschwindet im Kalender.
- **Admin\BookingController**: Liste/Filter, Storno setzt cancelled.
- **OptionController**: Update schreibt korrekte `bs_options`-Zeile (Default-Locale).
- **Kalender-Integration**: Admin storniert fremde Buchung; „für Mitglied buchen" ordnet uid korrekt zu; `see-data`-Gating (Admin sieht Namen, normales Mitglied nicht).
- **Gate::before**: `@can`/`can:`-Middleware respektieren admin/assist.

## 8. Reihenfolge der Umsetzung (für den Plan)

1. `Gate::before` + Privilegien-Konstante + Admin-Layout/Nav + Dashboard + Zugriffstests.
2. UserController (komplex, höchster Wert).
3. EventController.
4. Admin\BookingController (Liste/Storno).
5. OptionController.
6. Kalender-Integration: cancel-any, see-data/see-past-Gating, für-Mitglied-buchen.
