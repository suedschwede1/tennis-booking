# Design Guide — Aktueller Stand (2026-06-29)

> **Referenzdokument.** Beschreibt das tatsächlich implementierte Design-System. Bei Widersprüchen mit älteren Specs gilt dieses Dokument.

---

## CSS-Architektur

Das Projekt nutzt **zwei parallele Styling-Schichten**:

| Schicht | Datei | Einsatz |
|---------|-------|---------|
| CSS-Komponenten (`ui-*`) | `resources/css/app.css` | Admin-Views, Layouts |
| Tailwind-Utilities | inline im HTML | Kalender, vereinzelte Hilfsstyles |
| Legacy | `public/css/booking.css` | Kalender-Grid, `default-button`, Formulare im Kalender |

**Priorität bei Konflikten:** `booking.css` wird NACH Tailwind geladen → überschreibt globale Elemente wie `body`, `input[type="text"]`. Admin-Views sind davon nicht betroffen, da sie eigene Klassen verwenden.

---

## CSS-Variablen

### `@theme` (Tailwind v4)
```css
--font-display: 'Red Hat Display', sans-serif;
--font-body:    'Red Hat Text', sans-serif;
--color-accent-*: oklch(...) /* 50–950 */
```

### `:root` (globale Design-Tokens)
```css
--ui-orange:        #bf4316
--ui-orange-hover:  #9e3412
--ui-orange-soft:   #fde8e1
--ui-text:          #151515
--ui-text-secondary:#6a6e73
--ui-text-muted:    #b8b8b8
--ui-bg:            #fafafa
--ui-surface:       #ffffff
--ui-border:        #e0e0e0
--ui-sidebar:       #1b1d21
--ui-shadow:        0 2px 8px rgba(0,0,0,0.1)
```

---

## Seiten-Layout (Admin)

```blade
@extends('layouts.admin')
@section('admin-title', __('...'))
@section('admin-content')
<div class="ui-page">

    <div class="ui-page-header flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1>Seitentitel</h1>
            <p>Optionale Beschreibung</p>
        </div>
        <a href="..." class="ui-btn ui-btn-primary">+ Neu</a>
    </div>

    {{-- Cards hier --}}

</div>
@endsection
```

**Klassen:**
- `.ui-page` — vertikaler Stack mit `gap: 1.35rem`
- `.ui-page-header h1` — Display-Font, 1.625rem, bold
- `.ui-page-header p` — Sekundärtext, max 46rem

---

## Card

```html
<div class="ui-card">
    <div class="ui-card-header">
        <h2>Titel</h2>
        <a href="..." class="ui-btn ui-btn-primary">Aktion</a>
    </div>
    <div class="ui-card-body">
        Inhalt
    </div>
</div>
```

**Varianten:**
- `.ui-card-body-compact` — weniger Padding (`0.85rem 1.1rem`)
- `.ui-card--filter` — Filter-Cards: reduziertes Padding im Body

**Card-Header** hat `display: flex; justify-content: space-between` — links Titel, rechts Aktion.

---

## Filter-Bar

```blade
<div class="ui-card ui-card--filter">
    <div class="ui-card-body ui-stack">
        <p class="ui-section-label !mb-0">Filter</p>
        <form method="GET" action="{{ route(...) }}" class="ui-row">
            <div class="ui-field min-w-[16rem] flex-1">
                <label class="ui-label">Suche</label>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="ui-input">
            </div>
            <div class="ui-field min-w-[12rem]">
                <label class="ui-label">Status</label>
                <select name="status" class="ui-select">...</select>
            </div>
            <button type="submit" class="ui-btn ui-btn-primary">Suchen</button>
        </form>
    </div>
</div>
```

---

## Formulare

### Struktur
```html
<form class="ui-form-shell">
    <div class="ui-form-panel">
        <p class="ui-section-label">Abschnitt</p>
        <div class="ui-grid-2">
            <div class="ui-field">
                <label class="ui-label">Feldname</label>
                <input type="text" class="ui-input">
                <p class="ui-help">Hinweistext</p>
            </div>
        </div>
    </div>
    <div class="ui-form-actions">
        <div class="ui-form-actions-group">
            <button type="submit" class="ui-btn ui-btn-primary">Speichern</button>
            <a href="..." class="ui-btn ui-btn-ghost">Abbrechen</a>
        </div>
        <form method="POST" ...>@csrf @method('DELETE')
            <button type="submit" class="ui-btn ui-btn-danger"
                    onsubmit="return confirm({{ Js::from(__('...')) }})">Löschen</button>
        </form>
    </div>
</form>
```

### Form-Klassen

| Klasse | Bedeutung |
|--------|-----------|
| `.ui-form-shell` | Äußerer flex-column Stack |
| `.ui-form-panel` | Abschnitts-Gruppe |
| `.ui-form-divider` | Alternative zu panel, flexibler |
| `.ui-form-actions` | Actions-Zeile (space-between) |
| `.ui-form-actions-group` | Gruppe von Buttons (flex, gap) |
| `.ui-section-label` | Abschnittstitel (uppercase, tiny) |
| `.ui-field` | Einzelnes Feld (flex-column, gap) |
| `.ui-label` | Label (13px, semibold, sekundär) |
| `.ui-help` | Hilfstext (12px, sekundär) |
| `.ui-error` | Fehlermeldung (12px, rot) |
| `.ui-note` | Notiz/Beschreibung (12px) |

### Input-Klassen

| Element | Klasse |
|---------|--------|
| Text, Number, Email, Password | `.ui-input` |
| Select | `.ui-select` |
| Textarea | `.ui-textarea` |
| Date | `.ui-input` (type="date") |
| Select multiple | `.ui-select` → hat eigenes Styling |
| Checkbox | Kein ui-Klasse — nativ in `<label class="flex items-center gap-2">` |

### Grid-Layout im Formular

```html
<div class="ui-grid-2">...</div>   <!-- 2 Spalten -->
<div class="ui-grid-3">...</div>   <!-- 3 Spalten -->
<div class="ui-grid-4">...</div>   <!-- 4 Spalten, bricht bei ≤1024px auf 2 um -->
<div class="ui-stack">...</div>    <!-- vertikaler Stack -->
<div class="ui-row">...</div>      <!-- horizontale Reihe, flex-wrap -->
```

---

## Buttons

```html
<button class="ui-btn ui-btn-primary">Primär (Orange)</button>
<button class="ui-btn ui-btn-outline">Outline (Orange-Border)</button>
<button class="ui-btn ui-btn-ghost">Ghost (transparent)</button>
<button class="ui-btn ui-btn-danger">Löschen (Rot)</button>
```

| Klasse | Aussehen |
|--------|----------|
| `.ui-btn-primary` | Orange Hintergrund, weiß |
| `.ui-btn-outline` | Weißer Hintergrund, orange Rahmen |
| `.ui-btn-ghost` | Transparent, dunkelgrau |
| `.ui-btn-danger` | Rot (#c9190b), weiß |

**Größe:** Standard `min-height: 38px`. In Tabellen: `.ui-btn-ghost`, `.ui-btn-outline`, `.ui-btn-danger` → `min-height: 34px` (automatisch via `ui-table`-Kontext).

---

## Tabellen

```html
<div class="ui-table-wrap">
    <table class="ui-table">
        <thead>
            <tr>
                <th>Spalte</th>
                <th class="text-right">Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="font-medium">Wert</td>
                <td>
                    <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                        <a href="..." class="ui-btn ui-btn-ghost">Bearbeiten</a>
                        <form method="POST" ... class="m-0"
                              onsubmit="return confirm({{ Js::from(__('...')) }})">
                            @csrf @method('DELETE')
                            <button type="submit" class="ui-btn ui-btn-danger">Löschen</button>
                        </form>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</div>
```

**Wichtig:** Letzte `th`/`td` werden automatisch `text-align: right` gesetzt. Aktions-Spalte immer rechts.

---

## Badges

```html
<span class="ui-badge ui-badge-success">Aktiv</span>
<span class="ui-badge ui-badge-danger">Storniert</span>
<span class="ui-badge ui-badge-info">Info</span>
```

| Klasse | Farbe |
|--------|-------|
| `.ui-badge-success` | Grün (`#3e8635` auf `#f0faf0`) |
| `.ui-badge-danger` | Rot (`#c9190b` auf `#f9f0f0`) |
| `.ui-badge-info` | Blau (`#1a3a6b` auf `#eff6ff`) |

---

## KPI-Karten (Dashboard)

```html
<div class="ui-card ui-kpi">
    <div class="ui-card-body">
        <p class="ui-kpi-label">Buchungen heute</p>
        <p class="ui-kpi-value">42</p>
        <p class="ui-kpi-meta">Starplatz: 18 · Leitenplatz: 24</p>
    </div>
</div>
```

Für eine Reihe von 4 KPIs:
```html
<div class="ui-grid-4">
    <div class="ui-card ui-kpi">...</div>
    <div class="ui-card ui-kpi">...</div>
    <div class="ui-card ui-kpi">...</div>
    <div class="ui-card ui-kpi">...</div>
</div>
```

`.ui-kpi` fügt `border-top: 3px solid var(--ui-orange)` hinzu.

---

## Flash-Meldungen

Werden im `layouts/admin.blade.php` automatisch gerendert:

```html
<div class="mx-6 mt-6 ui-flash ui-flash-success">{{ session('success') }}</div>
<div class="mx-6 mt-6 ui-flash ui-flash-error">{{ $errors->first() }}</div>
```

---

## Popup-Layout

Für `?popup=1` Requests:

```html
<div class="ui-popup-shell">
    <div class="ui-popup-header">
        <h1>Titel</h1>
    </div>
    <div class="ui-popup-body">
        Inhalt
    </div>
    <div class="ui-popup-actions">
        <button class="ui-btn ui-btn-primary">Speichern</button>
    </div>
</div>
```

---

## Pane-Switcher (Tab-Umschalter)

```html
<div class="ui-pane-switch">
    <button id="tab-booking" class="ui-btn ui-btn-primary">Buchung</button>
    <button id="tab-event" class="ui-btn ui-btn-ghost">Veranstaltung</button>
</div>
```

JS toggled `ui-btn-primary` ↔ `ui-btn-ghost` per Klick.

---

## Kalender-Header-Navigation

```html
<div class="ui-calendar-nav">
    <a href="..." class="ui-calendar-nav-btn ui-calendar-nav-btn--arrow">◄</a>
    <a href="..." class="ui-calendar-nav-btn ui-calendar-nav-btn--today">Heute</a>
    <form class="ui-calendar-date-form">
        <input type="date" class="ui-calendar-date-input">
    </form>
    <a href="..." class="ui-calendar-nav-btn ui-calendar-nav-btn--arrow">►</a>
</div>
```

---

## Blade-Regeln

### Bekannte Fallstricke

**1. `@php($expr)` mit CRLF-Dateien → NICHT verwenden.**  
Blade kompiliert `@php($expr)` auf CRLF-Dateien ohne schließendes `?>` → bricht alle nachfolgenden Direktiven.

```blade
{{-- FALSCH --}}
@php($isToday = $d->format('Y-m-d') === $today)

{{-- RICHTIG --}}
@php
    $isToday = $d->format('Y-m-d') === $today;
@endphp
```

**2. Arrow Functions in `@foreach` → NICHT verwenden.**

```blade
{{-- FALSCH — Blade parst => als Ende des Direktiv-Arguments --}}
@foreach($collection->sortBy(fn($x) => $x->name) as $item)

{{-- RICHTIG --}}
@php $sorted = $collection->sortBy(fn($x) => $x->name); @endphp
@foreach($sorted as $item)
```

**3. Verschachtelte Klammern in `@continue`/`@break` → Variable vorziehen.**

```blade
{{-- KANN Probleme machen --}}
@continue(!empty($arr[$a][$b][$c]))

{{-- SICHER --}}
@php $skip = !empty($arr[$a][$b][$c]); @endphp
@continue($skip)
```

**4. `onsubmit`-Confirm immer mit `Js::from()`:**

```blade
{{-- FALSCH --}}
onsubmit="return confirm('{{ __('...') }}')"

{{-- RICHTIG --}}
onsubmit="return confirm({{ Js::from(__('...')) }})"
```

---

## Schriften

| Zweck | Font | Klasse/Variable |
|-------|------|----------------|
| Überschriften (H1, H2, KPI-Wert) | Red Hat Display | `font-family: var(--font-display)` |
| Fließtext, Labels, Inputs | Red Hat Text | `var(--font-body)` / Standard |

---

## Deployment

- **Lokal entwickeln**, `npm run build` ausführen
- `public/build/` committen (Vite-Output)
- Per FTP zu one.com hochladen (kein Node.js auf dem Server)
- PHP dev-server: `php artisan serve --port=8001` (WSL2)
- Nach Template-Änderungen: `php artisan view:clear` oder Server neu starten
