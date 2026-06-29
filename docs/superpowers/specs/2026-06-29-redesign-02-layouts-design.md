# Design: Redesign Sub-Projekt 2 — Layouts

**Datum:** 2026-06-29
**Serie:** UI-Redesign (7 Sub-Projekte)
**Ziel:** Bestehende Layouts auf Tailwind v4 umstellen, Header und Admin-Sidebar als eigene Blade-Komponenten auslagern.

---

## Kontext

Tailwind v4 + Alpine.js sind aus Sub-Projekt 1 verfügbar. Die Design-Referenzen liegen in `C:\Users\hmayer\Downloads\tennis-design-temp\design_handoff\blade-templates\layouts\`.

**Bestehende Dateien:**
- `resources/views/layouts/app.blade.php` — 105 Zeilen, Custom CSS Klassen
- `resources/views/layouts/admin.blade.php` — 47 Zeilen, Custom CSS Klassen
- `resources/views/layouts/popup.blade.php` — 13 Zeilen

---

## Neue Struktur

```
resources/views/
  components/
    layout/
      header.blade.php          ← NEU: Kalender-Header
      admin-sidebar.blade.php   ← NEU: Admin-Sidebar
  layouts/
    app.blade.php               ← MODIFIZIERT: verwendet <x-layout.header>
    admin.blade.php             ← MODIFIZIERT: verwendet <x-layout.admin-sidebar>
    popup.blade.php             ← MODIFIZIERT: minimales Cleanup
```

Alle Styles in **Tailwind-Utility-Klassen**. Kein Inline-Style, keine neuen Custom-CSS-Klassen in `booking.css`.

---

## `components/layout/header.blade.php`

Anonyme Blade-Komponente ohne Props. Liest `config('booking.*)` und Auth-Status direkt.

**Layout:**
```
<div class="bg-[#eae8e2] flex items-center gap-3 px-4 py-3">
  <!-- Linke Box: Logo + Name + Datum-Navigation -->
  <div class="bg-white border border-[#cccccc] rounded flex items-center gap-3 px-4 py-2">
    Logo (config: logo_path, logo_width, logo_height)
    Systemname (font-[family-name:var(--font-display)], text-lg, font-bold, text-[#151515])
    @stack('header-nav')   ← Datum-Navigation aus calendar/index.blade.php
  </div>

  <!-- Rechte Box: Action-Buttons -->
  <div class="bg-white border border-[#cccccc] rounded flex items-center gap-2 px-4 py-2 ml-auto">
    @hasSection('calendar-system-info') → "Infos" Button
    @hasSection('calendar-help') → "Hinweise" Button
    @auth → "Meine Buchungen" / "Mein Konto" / "Administration" (@can) / "Abmelden" / "?"
    @else → "Anmelden" Button
    @endauth
  </div>
</div>
```

**Button-Stil:** `text-sm font-medium text-[#151515] hover:text-[#bf4316] transition-colors`

**Help/Info-Buttons** behalten `data-panel-toggle` Attribute für bestehendes JS.

---

## `components/layout/admin-sidebar.blade.php`

Anonyme Blade-Komponente mit einem Prop: `$active` (string).

```php
@props(['active' => ''])
```

**Layout:**
```
<aside class="w-[200px] min-h-screen bg-[#1b1d21] flex flex-col">

  <!-- Section-Label -->
  <div class="px-4 pt-5 pb-2 text-[11px] uppercase tracking-widest text-[#6a6e73]">
    Administration
  </div>

  <!-- Nav-Items -->
  <nav class="flex-1">
    @foreach Nav-Items as [$key, $label, $route]
      <a href="{{ route($route) }}"
         @class([
           'flex items-center px-[19px] py-[9px] text-sm transition-colors',
           'text-white font-semibold bg-white/10 border-l-[3px] border-[#bf4316]' => $active === $key,
           'text-[#a0a0a0] hover:text-white border-l-[3px] border-transparent' => $active !== $key,
         ])>
        {{ $label }}
      </a>
    @endforeach
  </nav>

  <!-- Footer -->
  <div class="p-4 border-t border-white/10">
    <a href="{{ route('calendar.index') }}" class="text-[#a0a0a0] hover:text-white text-sm">
      ← Zum Kalender
    </a>
  </div>
</aside>
```

**Nav-Items (Reihenfolge):**
| Key | Label | Route |
|---|---|---|
| `dashboard` | Dashboard | `admin.dashboard` |
| `users` | Mitglieder | `admin.users.index` |
| `bookings` | Buchungen | `admin.bookings.index` |
| `events` | Veranstaltungen | `admin.events.index` |
| `squares` | Plätze | `admin.squares.index` |
| `config` | Einstellungen | `admin.config.edit` |

---

## `layouts/app.blade.php`

**Was sich ändert:**
- `<header class="top-header ...">` Block (Zeilen 17–60) wird ersetzt durch `<x-layout.header />`
- Help-Panel Sections (`calendar-system-info`, `calendar-help`) bleiben in `<main>` erhalten
- Feedback-Modal bleibt unverändert
- `@stack('scripts')` und `booking.js` bleiben unverändert

**Was bleibt:** Google Fonts Link, `@vite()`, `booking.css` Link, `@stack('head')`, `<main>` Struktur.

---

## `layouts/admin.blade.php`

**Was sich ändert:**
- Sidebar-HTML (Zeilen 7–32) wird ersetzt durch `<x-layout.admin-sidebar :active="$active ?? ''" />`
- Layout-Wrapper: `<div class="flex min-h-screen">` statt `admin-shell` Custom-Klasse
- Content-Area: `<div class="flex-1 bg-[#fafafa]">` mit `<main class="p-6">`
- Flash-Messages: Tailwind-Klassen statt `admin-flash*`

**`$active` Variable:** Wird vom Controller oder der View per `@php $active = 'dashboard'; @endphp` gesetzt.

---

## `layouts/popup.blade.php`

Minimale Änderungen:
- Font-Familie: `font-[family-name:var(--font-body)]` auf `<body>`
- Flash-Messages: Tailwind-Klassen (grün für success, rot für error)
- Kein Layout-Umbau

---

## Was sich NICHT ändert

- `resources/views/calendar/index.blade.php` — `@stack('header-nav')` bleibt unverändert
- `public/js/booking.js` — data-panel-toggle JS bleibt funktional
- `public/css/booking.css` — wird parallel geladen, nicht entfernt
- Alle anderen Views — werden in späteren Sub-Projekten umgestellt

---

## Erfolgskriterien

1. Kalender-Seite: Beige Header, Logo, Datum-Navigation sichtbar, Action-Buttons rechts
2. Admin-Seite: Dunkle Sidebar, aktiver Nav-Eintrag mit oranger Border
3. Help-Panels (Infos/Hinweise) öffnen/schließen noch korrekt
4. Feedback-Modal (Flash-Messages) funktioniert noch
5. `npm run build` ohne Fehler
