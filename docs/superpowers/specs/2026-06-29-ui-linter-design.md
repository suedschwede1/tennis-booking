# Design Spec: UI-Linter (`php artisan lint:ui`)

**Datum:** 2026-06-29  
**Status:** Approved

---

## Ziel

Ein Artisan-Command, der alle Blade-Templates auf Verstöße gegen den Design Guide
prüft und Warnungen mit Datei + Zeilennummer ausgibt. Rein warnend (kein
blockierender Exit-Code 1). CI-tauglich durch stabilen Exit-Code 0.

---

## Architektur

```
app/Console/Commands/LintUiCommand.php   ← Artisan-Command, Ausgabe-Formatierung
app/Services/UiLinter.php               ← Datei-Scanner, Regel-Ausführung
app/Services/UiRules.php                ← Regel-Definitionen als PHP-Array
```

### `UiRules.php` — Regelstruktur

Jede Regel ist ein assoziatives Array:

```php
[
    'id'      => 'BTN-01',
    'warn'    => 'Button verwendet bg-orange-* — Design Guide schreibt bg-[#bf4316] vor',
    'pattern' => '/<button[^>]*bg-orange-/i',
    'exclude' => [],   // Pfad-Präfixe die übersprungen werden (z.B. 'emails/')
]
```

`pattern` ist ein PCRE-Regex der auf jede Zeile einzeln angewendet wird.  
`exclude` ist eine Liste von Pfad-Teilstrings (relativ zu `resources/views/`).

---

## Regelkatalog

### Farben (CLR)

| ID | Muster | Warnung |
|----|--------|---------|
| CLR-01 | Hex-Farbe nicht in Palette | Farbe `#XXXXXX` nicht im Design Guide — Palette prüfen |
| CLR-02 | `text-red-500` | Fehlertext: `text-red-600` verwenden |
| CLR-03 | `border-red-500` | Fehler-Border: `border-red-400` verwenden |

**Erlaubte Hex-Farben (Whitelist):**
`#bf4316`, `#9e3412`, `#fde8e1`, `#151515`, `#6a6e73`, `#b8b8b8`, `#fafafa`,
`#ffffff`, `#e0e0e0`, `#f0f0f0`, `#f0faf0`, `#3e8635`, `#f9f0f0`, `#c9190b`,
`#f0f8ff`, `#0066cc`, `#fff3cd`, `#f0ab00`, `#eff6ff`, `#f4f4f4`, `#1b1d21`,
`#a0a0a0`, `#eae8e2`, `#e8e8e8`, `#ebebeb`, `#f5f5f5`, `#c7c7c7`, `#d1cbc0`,
`#8a8d90`, `#1a3a6b`, `#7f2010`, `#fff8f6`, `#fff3f0`, `#a84433`, `#c75518`

Ausnahmen für CLR-01: `resources/views/emails/`

### Buttons (BTN)

| ID | Muster | Warnung |
|----|--------|---------|
| BTN-01 | `<button` mit `bg-orange-` | Falsches Orange — `bg-[#bf4316]` verwenden |
| BTN-02 | `<button` mit `bg-red-` | Falsches Rot — `bg-[#c9190b]` für Danger-Buttons |
| BTN-03 | `<button` mit `rounded-full` | Buttons: `rounded` (6px), nicht `rounded-full` |
| BTN-04 | `<button` ohne `h-9` und ohne `h-10` | Button ohne Höhenklasse — `h-9` (36px) prüfen |

### Inputs / Select / Textarea (INP)

| ID | Muster | Warnung |
|----|--------|---------|
| INP-01 | `<input` oder `<select` oder `<textarea` mit `rounded-full` | Formularfelder: `rounded` (6px), nicht `rounded-full` |
| INP-02 | `<input` oder `<select` oder `<textarea` mit `rounded-lg` | Formularfelder: `rounded`, nicht `rounded-lg` |
| INP-03 | `<input` oder `<select` oder `<textarea` mit `rounded-xl` | Formularfelder: `rounded`, nicht `rounded-xl` |
| INP-04 | `<input` mit `focus:ring-blue-` | Focus-Ring: `focus:ring-[#bf4316]` verwenden |

### Badges (BDG)

| ID | Muster | Warnung |
|----|--------|---------|
| BDG-01 | Element mit Badge-Pattern und `rounded-full` | Badges: `rounded` (4px), nicht `rounded-full` |

Badge-Pattern: Zeile enthält gleichzeitig `px-` + `py-` + (`text-xs` oder `text-[11px]`) + `rounded-full`.

### Tabellen (TBL)

| ID | Muster | Warnung |
|----|--------|---------|
| TBL-01 | `<thead` ohne `uppercase` auf derselben oder Folgezeile (±3 Zeilen) | Tabellenkopf: `uppercase` fehlt |
| TBL-02 | `<thead` ohne `bg-[#fafafa]` in ±3 Zeilen | Tabellenkopf: `bg-[#fafafa]` fehlt |

TBL-Regeln prüfen einen Kontext-Fenster von ±3 Zeilen um das Muster herum.

### Typografie (TYP)

| ID | Muster | Warnung |
|----|--------|---------|
| TYP-01 | `text-[` gefolgt von Zahl unter 10 + `px]` | Schriftgröße unter 10px — Design-Minimum beachten |
| TYP-02 | `font-serif` oder `font-mono` | Nur Red Hat Display/Text erlaubt |

### Modals (MOD)

| ID | Muster | Warnung |
|----|--------|---------|
| MOD-01 | Zeile enthält `modal` (case-insensitive) und `bg-` aber nicht `bg-[#1b1d21]` | Modal-Header: `bg-[#1b1d21]` prüfen |

MOD-01 ist absichtlich breit — false positives sind bei warnend-only akzeptabel.

### Inline Styles (STY)

| ID | Muster | Warnung |
|----|--------|---------|
| STY-01 | `style="` | Inline-Style — Tailwind oder booking.css verwenden |

Ausnahmen für STY-01: `resources/views/emails/`

---

## Scanner-Logik (`UiLinter.php`)

```
1. Alle *.blade.php unter resources/views/ rekursiv sammeln
2. Für jede Datei:
   a. Zeilen einlesen
   b. Für jede Regel prüfen ob Datei excluded ist → überspringen
   c. Für Zeilen-basierte Regeln (alle außer TBL): Regex auf jede Zeile
   d. Für Kontext-Regeln (TBL): Sliding-Window über 7 Zeilen
   e. Treffer sammeln als { rule_id, file, line, message }
3. Ergebnis zurückgeben
```

---

## Ausgabe (`LintUiCommand.php`)

```
[WARN] BTN-01  resources/views/admin/events/index.blade.php:88
       Button verwendet bg-orange-500 — Design Guide schreibt bg-[#bf4316] vor

[WARN] INP-02  resources/views/account/edit.blade.php:34
       Input hat rounded-lg — soll rounded (6px) sein

─────────────────────────────────────────
12 Warnungen in 47 Dateien.
```

Bei 0 Befunden: `Keine Verstöße gefunden. 47 Dateien geprüft.`

Exit-Code immer 0.

**Option `--summary`:** Nur die Zusammenfassung, keine Einzel-Warnungen.  
**Option `--rule=BTN`:** Nur Regeln einer Kategorie ausgeben.

---

## Ausnahmen (Pfad-Exclusions)

| Pfad | Ausgenommene Regeln |
|------|---------------------|
| `emails/` | CLR-01, STY-01 |

Weitere Ausnahmen können in `UiRules.php` pro Regel ergänzt werden.

---

## Nicht in Scope

- Automatisches Fixen von Verstößen
- Pre-Commit-Hook-Integration (separates Feature)
- JavaScript/CSS-Dateien (nur Blade)
- Livewire/Alpine-Attribute (zu komplex für statische Analyse)
