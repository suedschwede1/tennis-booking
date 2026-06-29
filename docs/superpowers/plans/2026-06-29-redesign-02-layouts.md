# UI-Redesign Layouts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Header und Admin-Sidebar als Blade-Komponenten auslagern, alle 3 Layouts auf Tailwind v4 umstellen.

**Architecture:** Anonyme Blade-Komponenten in `resources/views/components/layout/`. Der Admin-Sidebar verwendet `request()->routeIs()` für aktive Zustände (kein Prop nötig — besser als explizites `$active`). `app.blade.php` behält `booking.css` + `booking.js` parallel (werden in späteren Sub-Projekten entfernt).

**Tech Stack:** Laravel Blade, Tailwind CSS v4, bestehende `@stack('header-nav')` und `data-panel-toggle` JS bleiben unverändert.

---

## File Structure

| Aktion | Datei |
|---|---|
| Erstellen | `resources/views/components/layout/header.blade.php` |
| Erstellen | `resources/views/components/layout/admin-sidebar.blade.php` |
| Modifizieren | `resources/views/layouts/app.blade.php` |
| Modifizieren | `resources/views/layouts/admin.blade.php` |
| Modifizieren | `resources/views/layouts/popup.blade.php` |

---

### Task 1: `components/layout/header.blade.php` erstellen

**Files:**
- Create: `resources/views/components/layout/header.blade.php`

- [ ] **Schritt 1: Verzeichnis anlegen**

```bash
mkdir -p C:\development\bookingnew\resources\views\components\layout
```

- [ ] **Schritt 2: Datei erstellen**

Erstelle `C:\development\bookingnew\resources\views\components\layout\header.blade.php`:

```blade
@php
    $bookingName = trim((string) \App\Models\Option::getValue('service.name', config('booking.name')));
    $bookingName = $bookingName !== '' ? $bookingName : config('booking.name');
@endphp
<header class="no-print bg-[#eae8e2] flex items-stretch gap-3 px-3 py-3">

    {{-- Linke Box: Logo + Name + Datum-Navigation --}}
    <div class="bg-white border border-[#cccccc] rounded flex items-center gap-4 px-4 py-2">
        <a href="{{ route('calendar.index') }}"
           aria-label="{{ $bookingName }}"
           style="--booking-logo-width: {{ config('booking.logo_width') }}px; --booking-logo-height: {{ config('booking.logo_height') }}px;"
           class="shrink-0">
            <img src="{{ asset(config('booking.logo_path')) }}"
                 width="{{ config('booking.logo_width') }}"
                 height="{{ config('booking.logo_height') }}"
                 alt="{{ $bookingName }}"
                 class="block">
        </a>
        <div class="flex flex-col gap-1">
            <div class="text-[#151515] font-bold text-lg leading-tight"
                 style="font-family: var(--font-display)">{{ $bookingName }}</div>
            <div class="flex items-center gap-1">
                @stack('header-nav')
            </div>
        </div>
    </div>

    {{-- Rechte Box: Action-Buttons --}}
    <div class="bg-white border border-[#cccccc] rounded flex items-center gap-2 px-4 py-2 ml-auto">
        @hasSection('calendar-system-info')
            <button type="button"
                    class="text-sm font-medium text-[#151515] hover:text-[#bf4316] transition-colors px-2 py-1 header-help-toggle"
                    data-panel-toggle="system-info-panel">{{ __('booking.nav.info') }}</button>
        @endif
        @hasSection('calendar-help')
            <button type="button"
                    class="text-sm font-medium text-[#151515] hover:text-[#bf4316] transition-colors px-2 py-1 header-help-toggle"
                    data-panel-toggle="help-panel">{{ __('booking.nav.help') }}</button>
        @endif
        @auth
            <a href="{{ route('account.bookings') }}"
               class="text-sm font-medium text-[#151515] hover:text-[#bf4316] transition-colors px-2 py-1">{{ __('booking.nav.my_bookings') }}</a>
            <a href="{{ route('account.edit') }}"
               class="text-sm font-medium text-[#151515] hover:text-[#bf4316] transition-colors px-2 py-1">{{ __('booking.nav.my_account') }}</a>
            @can('admin.see-menu')
                <a href="{{ route('admin.dashboard') }}"
                   class="text-sm font-medium text-[#151515] hover:text-[#bf4316] transition-colors px-2 py-1">{{ __('booking.nav.admin') }}</a>
            @endcan
            <form method="POST" action="{{ route('logout') }}" class="inline m-0">
                @csrf
                <button type="submit"
                        class="text-sm font-medium text-[#151515] hover:text-[#bf4316] transition-colors px-2 py-1">{{ __('booking.nav.logout') }}</button>
            </form>
            <a href="#"
               class="text-sm font-medium text-[#151515] hover:text-[#bf4316] transition-colors px-2 py-1 header-help">?</a>
        @else
            <a href="{{ route('login', ['redirect_to' => url()->full()]) }}"
               class="text-sm font-medium text-[#151515] hover:text-[#bf4316] transition-colors px-2 py-1">{{ __('booking.nav.login') }}</a>
        @endauth
    </div>

</header>
```

- [ ] **Schritt 3: Commit**

```bash
git add resources/views/components/layout/header.blade.php
git commit -m "feat(layout): header als Blade-Komponente erstellt"
```

---

### Task 2: `layouts/app.blade.php` aktualisieren

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

Der bestehende `<header class="top-header ...">` Block (Zeilen 17–60) wird durch `<x-layout.header />` ersetzt. Alles andere bleibt unverändert.

- [ ] **Schritt 1: Header-Block ersetzen**

Lies `C:\development\bookingnew\resources\views\layouts\app.blade.php`.

Ersetze den gesamten `<header class="top-header no-print">` Block (Zeile 17 bis Zeile 60, inklusive schließendem `</header>`) durch:

```blade
    <x-layout.header />
```

Das Ergebnis soll so aussehen:

```blade
@php
    $bookingName = trim((string) \App\Models\Option::getValue('service.name', config('booking.name')));
    $bookingName = $bookingName !== '' ? $bookingName : config('booking.name');
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="format-detection" content="telephone=no">
    <title>@yield('title', $bookingName)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@600;700&family=Red+Hat+Text:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/booking.css') }}?v={{ filemtime(public_path('css/booking.css')) }}">
    @stack('head')
</head>
<body>
<div class="page-shell">
    <x-layout.header />

    <main id="content">
        @if(session('success') || session('error'))
            <div id="feedback-modal" class="booking-modal" style="display:block;">
                <div class="booking-modal__viewport">
                    <div class="booking-modal__card booking-modal__card--feedback">
                        <button id="feedback-modal-close" class="booking-modal__close" title="{{ __('booking.feedback.close') }}">&#x2715;</button>
                        <div class="booking-modal__header">
                            <h2>{{ session('success') ? __('booking.feedback.success') : __('booking.feedback.notice') }}</h2>
                        </div>
                        <div class="booking-modal__body">
                            <p class="{{ session('success') ? 'booking-modal__success' : 'booking-modal__warning' }} booking-modal__flash">{{ session('success') ?? session('error') }}</p>
                        </div>
                        <div class="booking-modal__actions">
                            <button type="button" id="feedback-modal-ok" class="default-button">{{ __('booking.feedback.close') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @hasSection('calendar-system-info')
            <section class="help-panel no-print" id="system-info-panel" hidden>
                @yield('calendar-system-info')
            </section>
        @endif

        @hasSection('calendar-help')
            <section class="help-panel no-print" id="help-panel" hidden>
                @yield('calendar-help')
            </section>
        @endif

        @yield('content')
    </main>
</div>

<script src="{{ asset('js/booking.js') }}?v={{ filemtime(public_path('js/booking.js')) }}"></script>
@stack('scripts')
</body>
</html>
```

**Wichtig:** Das `@php`-Block mit `$bookingName` am Anfang der Datei kann entfernt werden — die Komponente definiert `$bookingName` selbst. Nur den Block in `app.blade.php` entfernen, nicht den in der Komponente.

- [ ] **Schritt 2: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat(layout): app.blade.php verwendet x-layout.header"
```

---

### Task 3: `components/layout/admin-sidebar.blade.php` erstellen

**Files:**
- Create: `resources/views/components/layout/admin-sidebar.blade.php`

**Wichtig:** Kein `$active`-Prop — aktive Zustände werden via `request()->routeIs()` direkt in der Komponente erkannt (wie im Original).

- [ ] **Schritt 1: Datei erstellen**

Erstelle `C:\development\bookingnew\resources\views\components\layout\admin-sidebar.blade.php`:

```blade
<aside class="w-[200px] min-h-screen bg-[#1b1d21] flex flex-col shrink-0">

    <div class="px-4 pt-5 pb-2 text-[11px] uppercase tracking-widest text-[#6a6e73]"
         style="font-family: var(--font-body)">
        {{ __('booking.nav.admin') }}
    </div>

    <nav class="flex-1">
        <a href="{{ route('admin.dashboard') }}"
           @class([
               'flex items-center py-[9px] pl-[19px] pr-4 text-sm transition-colors border-l-[3px]',
               'text-white font-semibold bg-white/10 border-[#bf4316]' => request()->routeIs('admin.dashboard'),
               'text-[#a0a0a0] hover:text-white border-transparent' => !request()->routeIs('admin.dashboard'),
           ])
           style="font-family: var(--font-body)">{{ __('booking.admin.overview') }}</a>

        @if(Route::has('admin.users.index'))@can('admin.user')
        <a href="{{ route('admin.users.index') }}"
           @class([
               'flex items-center py-[9px] pl-[19px] pr-4 text-sm transition-colors border-l-[3px]',
               'text-white font-semibold bg-white/10 border-[#bf4316]' => request()->routeIs('admin.users.*'),
               'text-[#a0a0a0] hover:text-white border-transparent' => !request()->routeIs('admin.users.*'),
           ])
           style="font-family: var(--font-body)">{{ __('booking.admin.nav_users') }}</a>
        @endcan @endif

        @if(Route::has('admin.events.index'))@can('admin.event')
        <a href="{{ route('admin.events.index') }}"
           @class([
               'flex items-center py-[9px] pl-[19px] pr-4 text-sm transition-colors border-l-[3px]',
               'text-white font-semibold bg-white/10 border-[#bf4316]' => request()->routeIs('admin.events.*'),
               'text-[#a0a0a0] hover:text-white border-transparent' => !request()->routeIs('admin.events.*'),
           ])
           style="font-family: var(--font-body)">{{ __('booking.admin.nav_events') }}</a>
        @endcan @endif

        @if(Route::has('admin.bookings.index'))@can('admin.booking')
        <a href="{{ route('admin.bookings.index') }}"
           @class([
               'flex items-center py-[9px] pl-[19px] pr-4 text-sm transition-colors border-l-[3px]',
               'text-white font-semibold bg-white/10 border-[#bf4316]' => request()->routeIs('admin.bookings.*'),
               'text-[#a0a0a0] hover:text-white border-transparent' => !request()->routeIs('admin.bookings.*'),
           ])
           style="font-family: var(--font-body)">{{ __('booking.admin.nav_bookings') }}</a>
        @endcan @endif

        @if(Route::has('admin.squares.index'))@can('admin.config')
        <a href="{{ route('admin.squares.index') }}"
           @class([
               'flex items-center py-[9px] pl-[19px] pr-4 text-sm transition-colors border-l-[3px]',
               'text-white font-semibold bg-white/10 border-[#bf4316]' => request()->routeIs('admin.squares.*'),
               'text-[#a0a0a0] hover:text-white border-transparent' => !request()->routeIs('admin.squares.*'),
           ])
           style="font-family: var(--font-body)">{{ __('booking.admin.nav_courts') }}</a>
        @endcan @endif

        @if(Route::has('admin.config.edit'))@can('admin.config')
        <a href="{{ route('admin.config.edit') }}"
           @class([
               'flex items-center py-[9px] pl-[19px] pr-4 text-sm transition-colors border-l-[3px]',
               'text-white font-semibold bg-white/10 border-[#bf4316]' => request()->routeIs('admin.config.*'),
               'text-[#a0a0a0] hover:text-white border-transparent' => !request()->routeIs('admin.config.*'),
           ])
           style="font-family: var(--font-body)">{{ __('booking.admin.config') }}</a>
        @endcan @endif

        @if(Route::has('admin.testmail.index'))@can('admin.config')
        <a href="{{ route('admin.testmail.index') }}"
           @class([
               'flex items-center py-[9px] pl-[19px] pr-4 text-sm transition-colors border-l-[3px]',
               'text-white font-semibold bg-white/10 border-[#bf4316]' => request()->routeIs('admin.testmail.*'),
               'text-[#a0a0a0] hover:text-white border-transparent' => !request()->routeIs('admin.testmail.*'),
           ])
           style="font-family: var(--font-body)">Testmail</a>
        @endcan @endif
    </nav>

    <div class="p-4 border-t border-white/10">
        <a href="{{ route('calendar.index') }}"
           class="text-[#a0a0a0] hover:text-white text-sm transition-colors"
           style="font-family: var(--font-body)">← {{ __('booking.admin.to_calendar') }}</a>
    </div>

</aside>
```

- [ ] **Schritt 2: Commit**

```bash
git add resources/views/components/layout/admin-sidebar.blade.php
git commit -m "feat(layout): admin-sidebar als Blade-Komponente erstellt"
```

---

### Task 4: `layouts/admin.blade.php` + `layouts/popup.blade.php` aktualisieren

**Files:**
- Modify: `resources/views/layouts/admin.blade.php`
- Modify: `resources/views/layouts/popup.blade.php`

- [ ] **Schritt 1: `admin.blade.php` komplett ersetzen**

Ersetze den gesamten Inhalt von `C:\development\bookingnew\resources\views\layouts\admin.blade.php` durch:

```blade
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('booking.nav.admin') }} – @yield('admin-title', __('booking.admin.overview'))</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@600;700&family=Red+Hat+Text:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/booking.css') }}">
</head>
<body class="{{ request('popup') ? 'admin-popup-mode' : '' }}" style="font-family: var(--font-body)">

<div class="flex min-h-screen">
    @unless(request('popup'))
        <x-layout.admin-sidebar />
    @endunless

    <div class="flex-1 bg-[#fafafa] flex flex-col">
        @if(session('success'))
            <div class="bg-green-50 border-l-4 border-green-500 text-green-800 px-6 py-3 text-sm">
                {{ session('success') }}
            </div>
        @endif
        @if($errors->any())
            <div class="bg-red-50 border-l-4 border-red-500 text-red-800 px-6 py-3 text-sm">
                {{ $errors->first() }}
            </div>
        @endif
        <main class="p-6 flex-1">@yield('admin-content')</main>
    </div>
</div>

</body>
</html>
```

- [ ] **Schritt 2: `popup.blade.php` aktualisieren**

Ersetze den gesamten Inhalt von `C:\development\bookingnew\resources\views\layouts\popup.blade.php` durch:

```blade
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>@yield('title', config('app.name'))</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@600;700&family=Red+Hat+Text:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/booking.css') }}">
</head>
<body class="popup-body-root" style="font-family: var(--font-body)">
    @if(session('success'))
        <div class="bg-green-50 border-l-4 border-green-500 text-green-800 px-4 py-2 text-sm mb-2">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="bg-red-50 border-l-4 border-red-500 text-red-800 px-4 py-2 text-sm mb-2">
            {{ $errors->first() }}
        </div>
    @endif
    @yield('content')
</body>
</html>
```

- [ ] **Schritt 3: Commit**

```bash
git add resources/views/layouts/admin.blade.php resources/views/layouts/popup.blade.php
git commit -m "feat(layout): admin.blade.php und popup.blade.php auf Tailwind umgestellt"
```

---

### Task 5: Build aktualisieren und committen

**Files:** `public/build/`

- [ ] **Schritt 1: Build ausführen**

```bash
cd C:\development\bookingnew
npm run build
```

Erwartung: Kein Fehler. Neue gehashte Assets in `public/build/assets/`.

- [ ] **Schritt 2: Build committen**

```bash
git add public/build/
git commit -m "chore: Vite-Build nach Layout-Redesign aktualisiert"
```

---

## Manuelle Verifikation

Nach `npm run dev` im Browser prüfen:

1. **Kalender-Seite:** Beige Header (`#eae8e2`), Logo links, Datum-Navigation (`◄ Heute DD.MM.YYYY ►`), Action-Buttons rechts in weißer Box
2. **Help-Panels:** "Infos" und "Hinweise" Buttons öffnen/schließen die Panels korrekt
3. **Admin-Seite:** Dunkle Sidebar (`#1b1d21`), aktiver Eintrag mit orangem linkem Streifen (`#bf4316`), Content-Area grau (`#fafafa`)
4. **Popup-Modus (`?popup=1`):** Sidebar wird ausgeblendet
5. **Flash-Messages:** Grün für success, rot für errors — in Admin und Popup
6. **Logout:** Funktioniert noch (Form-Submit)
