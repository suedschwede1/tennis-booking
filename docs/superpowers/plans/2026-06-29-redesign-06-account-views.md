# UI-Redesign Account Views Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `account/edit.blade.php` und `account/bookings.blade.php` von Legacy-Inline-CSS auf Tailwind v4 + Design-System umstellen.

**Architecture:** Beide Views erweitern `@extends('layouts.app')` und füllen `@section('content')`. Die Seiten erhalten je einen `max-w-2xl`-Container mit Card-Komponenten in Tailwind. Keine Änderungen an Controllern, Routen oder Translation-Keys. Flash-Messages werden inline im Content-Bereich angezeigt (die Feedback-Modal-Komponente ist nur auf der Kalender-Seite verfügbar).

**Tech Stack:** Laravel 13 Blade, Tailwind CSS v4, Translation-Keys (`__('booking.*')`)

---

## File Structure

| Aktion | Datei |
|--------|-------|
| Modify | `resources/views/account/edit.blade.php` |
| Modify | `resources/views/account/bookings.blade.php` |
| Build  | `public/build/` |

---

### Task 1: `account/edit.blade.php` neu gestalten

**Files:**
- Modify: `resources/views/account/edit.blade.php`

- [ ] **Schritt 1: Aktuelle Datei lesen**

Lies `C:\development\bookingnew\resources\views\account\edit.blade.php` (68 Zeilen).

- [ ] **Schritt 2: Datei komplett ersetzen**

Ersetze den gesamten Inhalt durch:

```blade
@extends('layouts.app')
@section('title', __('booking.account.my_account'))

@section('content')
<div class="max-w-2xl mx-auto px-4 py-8 flex flex-col gap-6">

    <h1 class="text-2xl font-bold text-[#151515]"
        style="font-family: var(--font-display)">{{ __('booking.account.my_account') }}</h1>

    {{-- Flash-Meldungen --}}
    @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-lg px-4 py-3">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-600 text-sm rounded-lg px-4 py-3">
            {{ session('error') }}
        </div>
    @endif

    {{-- Card 1: Profil --}}
    <div class="bg-white rounded-xl border border-[#e0ddd7] shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-[#f0ede6]">
            <h2 class="text-base font-semibold text-[#151515]"
                style="font-family: var(--font-display)">Profil</h2>
        </div>
        <form method="POST" action="{{ route('account.update') }}">
            @csrf
            @method('PUT')
            <div class="px-6 py-5 flex flex-col gap-4">

                {{-- Anzeigename (full-width, required) --}}
                <div class="flex flex-col gap-1">
                    <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">
                        {{ __('booking.account.display_name') }}
                    </label>
                    <input type="text" name="alias"
                           value="{{ old('alias', $user->alias) }}"
                           required maxlength="128"
                           class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent {{ $errors->has('alias') ? 'border-red-400' : 'border-[#d1cbc0]' }}">
                    @error('alias')
                        <p class="text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- 2-Spalten-Grid --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">
                            {{ __('booking.account.firstname') }}
                        </label>
                        <input type="text" name="firstname"
                               value="{{ old('firstname', $profile['firstname']) }}"
                               maxlength="128"
                               class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                        @error('firstname')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">
                            {{ __('booking.account.lastname') }}
                        </label>
                        <input type="text" name="lastname"
                               value="{{ old('lastname', $profile['lastname']) }}"
                               maxlength="128"
                               class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                        @error('lastname')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">
                            {{ __('booking.account.email') }}
                        </label>
                        <input type="email" name="email"
                               value="{{ old('email', $user->email) }}"
                               maxlength="128"
                               class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent {{ $errors->has('email') ? 'border-red-400' : 'border-[#d1cbc0]' }}">
                        @error('email')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">
                            {{ __('booking.account.phone') }}
                        </label>
                        <input type="text" name="phone"
                               value="{{ old('phone', $profile['phone']) }}"
                               maxlength="128"
                               class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                        @error('phone')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">
                            {{ __('booking.account.street') }}
                        </label>
                        <input type="text" name="street"
                               value="{{ old('street', $profile['street']) }}"
                               maxlength="128"
                               class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                        @error('street')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">
                            {{ __('booking.account.zip') }}
                        </label>
                        <input type="text" name="zip"
                               value="{{ old('zip', $profile['zip']) }}"
                               maxlength="128"
                               class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                        @error('zip')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">
                            {{ __('booking.account.city') }}
                        </label>
                        <input type="text" name="city"
                               value="{{ old('city', $profile['city']) }}"
                               maxlength="128"
                               class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                        @error('city')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">
                            {{ __('booking.account.gender') }}
                        </label>
                        <select name="gender"
                                class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                            @php($gender = old('gender', $profile['gender']))
                            <option value="" @selected($gender === null || $gender === '')>–</option>
                            <option value="male" @selected($gender === 'male')>{{ __('booking.account.male') }}</option>
                            <option value="female" @selected($gender === 'female')>{{ __('booking.account.female') }}</option>
                        </select>
                    </div>

                </div>
            </div>
            <div class="px-6 pb-5 flex justify-end">
                <button type="submit"
                        class="bg-[#bf4316] hover:bg-[#9e3412] text-white text-sm font-medium px-5 py-2 rounded transition-colors">
                    {{ __('booking.admin.save') }}
                </button>
            </div>
        </form>
    </div>

    {{-- Card 2: Passwort ändern --}}
    <div class="bg-white rounded-xl border border-[#e0ddd7] shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-[#f0ede6]">
            <h2 class="text-base font-semibold text-[#151515]"
                style="font-family: var(--font-display)">{{ __('booking.account.password_change') }}</h2>
        </div>
        <form method="POST" action="{{ route('account.password') }}">
            @csrf
            @method('PUT')
            <div class="px-6 py-5 flex flex-col gap-4">

                <div class="flex flex-col gap-1">
                    <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">
                        {{ __('booking.account.current_password') }}
                    </label>
                    <input type="password" name="current_password"
                           required
                           class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent {{ $errors->has('current_password') ? 'border-red-400' : 'border-[#d1cbc0]' }}">
                    @error('current_password')
                        <p class="text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">
                        {{ __('booking.account.new_password') }}
                    </label>
                    <input type="password" name="password"
                           required
                           class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent {{ $errors->has('password') ? 'border-red-400' : 'border-[#d1cbc0]' }}">
                    @error('password')
                        <p class="text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">
                        {{ __('booking.account.new_password_confirmation') }}
                    </label>
                    <input type="password" name="password_confirmation"
                           required
                           class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                </div>

            </div>
            <div class="px-6 pb-5 flex justify-end">
                <button type="submit"
                        class="bg-[#bf4316] hover:bg-[#9e3412] text-white text-sm font-medium px-5 py-2 rounded transition-colors">
                    {{ __('booking.account.password_change') }}
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
```

- [ ] **Schritt 3: Commit**

```bash
git add resources/views/account/edit.blade.php
git commit -m "feat(account): Mein-Konto-Seite mit Tailwind v4 neu gestaltet"
```

---

### Task 2: `account/bookings.blade.php` neu gestalten

**Files:**
- Modify: `resources/views/account/bookings.blade.php`

- [ ] **Schritt 1: Aktuelle Datei lesen**

Lies `C:\development\bookingnew\resources\views\account\bookings.blade.php` (49 Zeilen).

- [ ] **Schritt 2: Datei komplett ersetzen**

Ersetze den gesamten Inhalt durch:

```blade
@extends('layouts.app')
@section('title', __('booking.account.my_bookings'))

@section('content')
<div class="max-w-2xl mx-auto px-4 py-8 flex flex-col gap-6">

    <h1 class="text-2xl font-bold text-[#151515]"
        style="font-family: var(--font-display)">{{ __('booking.account.my_bookings') }}</h1>

    {{-- Flash-Meldungen --}}
    @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-lg px-4 py-3">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-600 text-sm rounded-lg px-4 py-3">
            {{ session('error') }}
        </div>
    @endif

    <div class="bg-white rounded-xl border border-[#e0ddd7] shadow-sm overflow-hidden">

        @if($bookings->isEmpty())
            <div class="px-6 py-10 text-center flex flex-col items-center gap-3">
                <p class="text-sm text-[#6a6e73]">{{ __('booking.messages.no_active_bookings') }}</p>
                <a href="{{ route('calendar.index') }}"
                   class="text-sm text-[#bf4316] hover:underline">→ Zum Kalender</a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr>
                            <th class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73] px-4 py-3 border-b border-[#e0ddd7] text-left whitespace-nowrap">{{ __('booking.account.court') }}</th>
                            <th class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73] px-4 py-3 border-b border-[#e0ddd7] text-left whitespace-nowrap">{{ __('booking.account.date') }}</th>
                            <th class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73] px-4 py-3 border-b border-[#e0ddd7] text-left whitespace-nowrap">{{ __('booking.account.time') }}</th>
                            <th class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73] px-4 py-3 border-b border-[#e0ddd7] text-left whitespace-nowrap">{{ __('booking.account.status') }}</th>
                            <th class="px-4 py-3 border-b border-[#e0ddd7]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bookings as $booking)
                            @php($reservation = $booking->reservations->first())
                            <tr class="hover:bg-[#fafaf9]">
                                <td class="text-sm text-[#151515] px-4 py-3 border-b border-[#f0ede6]">
                                    {{ $booking->square?->display_name }}
                                </td>
                                <td class="text-sm text-[#151515] px-4 py-3 border-b border-[#f0ede6] whitespace-nowrap">
                                    {{ $reservation?->date }}
                                </td>
                                <td class="text-sm text-[#151515] px-4 py-3 border-b border-[#f0ede6] whitespace-nowrap">
                                    @if($reservation)
                                        {{ \Illuminate\Support\Str::of($reservation->time_start)->substr(0, 5) }}–{{ \Illuminate\Support\Str::of($reservation->time_end)->substr(0, 5) }} Uhr
                                    @endif
                                </td>
                                <td class="px-4 py-3 border-b border-[#f0ede6]">
                                    <span class="inline-block bg-green-50 text-green-700 text-xs rounded-full px-2 py-0.5">
                                        {{ $booking->status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 border-b border-[#f0ede6] text-right">
                                    <form method="POST" action="{{ route('bookings.destroy', $booking) }}"
                                          onsubmit="return confirm('{{ __('booking.messages.confirm_cancel_booking') }}')"
                                          class="m-0">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="text-xs text-red-600 hover:text-red-800 hover:underline transition-colors">
                                            {{ __('booking.account.cancel') }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    </div>

</div>
@endsection
```

- [ ] **Schritt 3: Commit**

```bash
git add resources/views/account/bookings.blade.php
git commit -m "feat(account): Meine-Buchungen-Seite mit Tailwind v4 neu gestaltet"
```

---

### Task 3: Build aktualisieren und committen

**Files:** `public/build/`

- [ ] **Schritt 1: Build ausführen**

```bash
cd C:\development\bookingnew
npm run build
```

Erwartung: `✓ built in Xms` ohne Fehler.

- [ ] **Schritt 2: Build committen**

```bash
git add public/build/
git commit -m "chore: Vite-Build nach Account-Views Redesign aktualisiert"
```

---

## Manuelle Verifikation

1. `/mein-konto` öffnen: zwei Cards (Profil + Passwort) auf beigem Hintergrund, alle Felder vorausgefüllt
2. Profil mit leerem Anzeigename absenden → rote Border + Fehlermeldung unter dem Feld
3. Profil erfolgreich speichern → grüne Flash-Meldung oben
4. Passwort ändern mit falschem Current-Password → rote Border + Fehlermeldung
5. `/meine-buchungen` öffnen: Tabelle mit eigenen Buchungen
6. "Abmelden"-Button klicken → `confirm()`-Dialog erscheint, bei OK wird DELETE gesendet
7. Ohne aktive Buchungen: Leer-Zustand mit Link zum Kalender
