# Peak-Zeit-Buchungslimit

**Datum:** 2026-06-30
**Status:** Approved

## Zusammenfassung

Buchungen während konfigurierbarer Stoßzeiten zählen gegen das bestehende `max_active_bookings`-Limit eines Platzes. Außerhalb der Stoßzeiten gilt kein Limit. Die Stoßzeiten werden global in den Admin-Einstellungen konfiguriert; pro Platz kann das Feature ein- oder ausgeschaltet werden.

## Motivation

Stoßzeiten (Abend, Wochenende) sind schnell ausgebucht während einzelne Mitglieder viele Slots belegen. Das bestehende globale Limit greift auch für Off-Peak-Zeiten und schränkt die Nutzung unnötig ein. Ein reines Peak-Limit erhöht die Fairness ohne Off-Peak-Buchungen zu behindern.

## Design

### 1. Admin-Einstellungen

Neuer Abschnitt "Stoßzeiten" auf der bestehenden Admin-Einstellungsseite (`OptionController`).

Gespeicherte `bs_options`-Keys:

| Key | Typ | Beispiel |
|-----|-----|---------|
| `peak_limit.enabled` | bool | `1` |
| `peak_limit.window_1_start` | time string | `08:00` |
| `peak_limit.window_1_end` | time string | `12:00` |
| `peak_limit.window_2_start` | time string | `17:00` |
| `peak_limit.window_2_end` | time string | `21:00` |

`config/booking.php` liefert die Fallback-Defaults für diese Werte.

### 2. Square-Konfiguration

Neuer Meta-Key `peak_limit_enabled` (Wert `0` oder `1`) in `bs_squares_meta`.

Im Admin-Square-Formular: neue Checkbox "Stoßzeiten-Limit aktiv", nur sichtbar wenn `peak_limit.enabled` global aktiv ist.

### 3. SquareValidator — Buchungsprüfung

Die bestehende Prüfung auf `max_active_bookings` wird erweitert:

**Vorher:** Zähle alle aktiven Buchungen des Users für diesen Platz.

**Nachher:**
1. Ist `peak_limit_enabled` für den Platz **nicht** aktiv → bisheriges Verhalten unverändert.
2. Ist `peak_limit_enabled` aktiv UND fällt `time_start` der neuen Buchung **nicht** in ein Peak-Fenster → kein Limit, Buchung erlaubt.
3. Ist `peak_limit_enabled` aktiv UND fällt `time_start` in ein Peak-Fenster → zähle nur aktive Buchungen des Users, deren `time_start` ebenfalls in ein Peak-Fenster fällt, und prüfe gegen `max_active_bookings`.

Peak-Fenster-Prüfung: `time_start >= window_start AND time_start < window_end`.

### 4. Fehlermeldung

Neue Übersetzung in `lang/de/booking.php`:

```php
'peak_limit_reached' => 'Du hast das Buchungslimit für Stoßzeiten erreicht.',
```

Wird angezeigt wenn Prüfung in Schritt 3 fehlschlägt.

## Was sich nicht ändert

- `max_active_bookings` bleibt unverändert auf `bs_squares` — kein Schema-Change.
- Off-Peak-Buchungen zählen weiterhin nicht gegen ein Limit (wenn Peak-Limit aktiv).
- Bestehende Buchungen werden nicht rückwirkend neu bewertet.

## Offene Fragen

keine
