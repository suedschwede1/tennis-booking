# Serientermin: Einzeltermin vs. ganze Serie bearbeiten

## Problem

Im Admin-Kalender wirkt jede Aktion (bearbeiten, stornieren, löschen) auf einen
Serientermin (`Booking` mit Status `subscription`) immer auf die **gesamte**
Serie — unabhängig davon, welcher konkrete Termin im Kalender angeklickt wurde.
Der Klick trägt heute nicht einmal das Datum des angeklickten Termins weiter.

Admins sollen künftig wählen können, ob eine Änderung nur den angeklickten
Termin betrifft oder die Serie ab diesem Termin.

## Datenmodell (Ist-Zustand, unverändert)

- `bs_bookings` (`Booking`): eine Zeile pro Buchung. `status` = `single` |
  `subscription` | `cancelled`. Kein Konzept von "Serie mit Ausnahmen" —
  Wiederholung ist rein denormalisiert über mehrere Reservations.
- `bs_reservations` (`Reservation`): eine Zeile pro Termin/Slot, `bid` verweist
  auf die Booking. Keine eigenen Status-/Notiz-Felder auf Reservation-Ebene.
- Es gibt **keine** `parent_id`/`rrule`/Ausnahme-Tabelle. Dieses Feature führt
  auch keine neuen Spalten ein — es nutzt ausschließlich Split (neue Booking-Zeile).

## Lösungsansatz: Split beim Bearbeiten

Da Status und Notizen nur auf Booking-Ebene existieren, wird "nur diesen
Termin" als **Abspaltung in eine neue, eigenständige Booking** umgesetzt
(analog Outlook/Google Calendar "diesen Termin" vs. "diesen und alle
folgenden").

### Scope-Optionen

1. **Nur diesen Termin** (Default): Die angeklickte `Reservation` wird aus der
   Serie herausgelöst in eine neue `Booking` mit Status `single`. Die neue
   Booking übernimmt `uid`, `sid`, `quantity`, `visibility` sowie die
   Booking-Meta (`owner-name`, `admin-note`, `player-names`) der
   Ursprungs-Booking. Die gewünschte Aktion (bearbeiten/stornieren/löschen)
   wird danach auf diese neue Einzelbuchung angewendet. Alle übrigen Termine
   der Serie bleiben unverändert in der alten Booking.

2. **Diesen und alle folgenden Termine**: Die Serie wird am Datum des
   angeklickten Termins gesplittet:
   - Alle Reservations mit Datum **vor** dem angeklickten Termin bleiben in der
     alten Booking. Ihr Status wird neu berechnet (`single`, wenn nur noch 1
     Reservation übrig bleibt; sonst `subscription`).
   - Alle Reservations **ab** dem angeklickten Datum (inklusive) wandern in
     eine neue Booking (Meta wie oben kopiert; Status analog neu berechnet).
   - Die gewünschte Aktion wird auf die neue Booking angewendet.
   - **Sonderfall:** Ist der angeklickte Termin bereits der erste (früheste)
     Termin der Serie, gibt es nichts vor ihm abzuspalten — die Aktion wirkt
     direkt auf die bestehende Booking (heutiges Verhalten, kein Split nötig).

3. Ist die Booking **keine** Subscription (nur 1 Reservation), gibt es keine
   Auswahl — Verhalten bleibt wie heute.

### Geltungsbereich

Gilt einheitlich für **Bearbeiten** (`update`), **Stornieren** (`cancel`) und
**Löschen** (`destroy`). Die drei bestehenden Forms auf der Edit-Seite
(Speichern, Stornieren, Löschen) übernehmen denselben Scope.

### Split-Vorgang (Details)

- Läuft transaktional (`DB::transaction`).
- Reservations werden per `Reservation::whereIn('rid', ...)->update(['bid' => $newBooking->bid])`
  umgehängt (keine Neuerstellung/kein Datenverlust an `ReservationMeta`).
- Booking-Meta (`bs_bookings_meta`: `owner-name`, `admin-note`, `player-names`)
  wird auf die neue Booking **kopiert**, nicht verschoben — beide Bookings
  existieren danach unabhängig voneinander.
- Bills (`bs_bookings_bills` via `bills()`) verbleiben bei der jeweiligen
  Booking, die auch die zugehörigen Reservations behält — ein Split partitioniert
  Reservations, ordnet aber keine bestehenden Bills um (Bills werden bei
  Subscriptions ohnehin nicht pro Termin geführt; falls doch vorhanden, bleiben
  sie auf der alten Booking).
- Nach dem Split: alte Booking-Status neu berechnen (`recalculateStatus()`:
  0 Reservations → Booking löschen [kann nur beim "erster Termin"-Sonderfall
  nicht auftreten, da dort kein Split erfolgt]; 1 Reservation → `single`;
  ≥2 → `subscription`). Gleiche Neuberechnung für die neue Booking.

## UI-Änderungen

### Kalender-Grid (`grid.blade.php`)

- Der `data-edit-url` für Serienzellen bekommt einen zusätzlichen
  Query-Parameter `occurrence={date}` (das Datum der angeklickten Zelle), z.B.
  `route('admin.bookings.edit', $reservation->booking) . '?occurrence=' . $dateKey`.

### Edit-Seite (`admin/bookings/edit.blade.php`)

- Neue Radio-Auswahl, **nur sichtbar wenn `$booking->isSubscription()`**:
  - "Nur diesen Termin" (checked/default)
  - "Diesen und alle folgenden Termine"
- Wird als `edit_scope` in den drei Forms (Speichern/PUT, Stornieren, Löschen)
  als Hidden-/Radio-Feld mitgeschickt, zusammen mit dem `occurrence`-Datum
  (aus dem Query-Parameter, als Hidden-Feld in alle drei Forms übernommen).
- Label zeigt zusätzlich an, auf welchen Termin sich "Nur diesen Termin"
  bezieht (z.B. Datum aus `occurrence`), damit klar ist, welcher Termin gemeint
  ist, wenn die Seite z.B. für den 3. Termin einer Serie geöffnet wurde.

## Controller/Service-Änderungen

- `BookingController::edit()`: nimmt `occurrence` (Query) entgegen, lädt die
  entsprechende `Reservation` (fällt zurück auf die erste, falls kein/ungültiger
  Parameter — heutiges Verhalten), reicht Occurrence-Datum + `isSubscription`
  an die View weiter.
- `BookingController::update()/cancel()/destroy()`: nehmen `occurrence` und
  `edit_scope` (`single_occurrence` | `this_and_following`) aus dem Request.
  Ist die Booking eine Subscription und ein gültiges `occurrence`-Datum
  vorhanden, wird zuerst `BookingService::splitForScope($booking, $occurrenceDate, $scope)`
  aufgerufen, das die zu bearbeitende (Teil-)Booking zurückgibt. Die
  bestehende Update/Cancel/Delete-Logik läuft danach unverändert auf dieser
  (ggf. neuen) Booking weiter.
- Neue Methode `BookingService::splitForScope(Booking $booking, Carbon $occurrenceDate, string $scope): Booking`:
  kapselt die Split-Logik aus dem Abschnitt oben. Gibt bei
  `single_occurrence` immer eine (neue) Ein-Termin-Booking zurück; bei
  `this_and_following` entweder die neue Booking oder — im "erster Termin"-
  Sonderfall — die unveränderte Ursprungs-Booking.

## Fehlerfälle

- `occurrence` fehlt oder passt zu keiner Reservation der Booking → Verhalten
  wie heute (Aktion wirkt auf gesamte Booking), kein Fehler — deckt auch
  bestehende Aufrufer/Alt-Links ab, die den Parameter noch nicht mitschicken.
- `edit_scope` fehlt, obwohl Booking Subscription ist → Default `single_occurrence`.

## Nicht Teil dieser Änderung

- Keine neue "Ausnahme"-Tabelle oder `rrule`-Unterstützung.
- Keine Änderung an der Erstellung von Serienterminen (`store()`), nur an
  Bearbeiten/Stornieren/Löschen bestehender Serien.
- Bills-Handling bei Split bleibt einfach (keine Aufteilung anteiliger Beträge)
  — passend zum aktuellen Stand, dass Subscriptions ohnehin `status_billing`
  pauschal auf Booking-Ebene führen.
