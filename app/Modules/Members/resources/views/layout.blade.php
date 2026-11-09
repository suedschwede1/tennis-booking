<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('booking.nav.members') }} - @yield('admin-title', __('members::admin.dashboard.title'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="tc-design members-admin" x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
<div class="members-shell">
    <aside id="members-navigation" class="members-nav" :class="{ 'members-nav--open': menuOpen }">
        <a class="members-nav-link" href="{{ route('calendar.index') }}">{{ __('booking.nav.back_to_calendar') }}</a>
        <nav aria-label="{{ __('booking.nav.members') }}" @click="if ($event.target.closest('a')) menuOpen = false">
            @foreach([
                ['members.dashboard', 'members.dashboard', 'dashboard.title'],
                ['members.index', 'members.index,members.create,members.edit,members.payments.*', 'members.title'],
                ['members.dues.index', 'members.dues.*', 'dues.title'],
                ['members.mail.index', 'members.mail.index,members.mail.templates.*', 'mail.title'],
                ['members.fiscal-years.index', 'members.fiscal-years.*', 'fiscal_years.title'],
                ['members.fee-categories.index', 'members.fee-categories.*', 'fee_categories.title'],
                ['members.functions.index', 'members.functions.*', 'functions.title'],
                ['members.mail.config', 'members.mail.config', 'mail.config_title'],
                ['members.dsgvo.index', 'members.dsgvo.*', 'dsgvo.title'],
            ] as [$route, $pattern, $label])
                <a class="members-nav-link" href="{{ route($route) }}" @if(request()->routeIs(...explode(',', $pattern))) aria-current="page" @endif>{{ __('members::admin.'.$label) }}</a>
            @endforeach
        </nav>
    </aside>
    <div class="members-main">
        <header class="members-header">
            <x-ui.button type="button" variant="secondary" class="members-menu-toggle" aria-controls="members-navigation" ::aria-expanded="menuOpen.toString()" @click="menuOpen = !menuOpen">{{ __('members::admin.review.menu') }}</x-ui.button>
            <h1>@yield('admin-title', __('members::admin.dashboard.title'))</h1>
            <div class="tc-row">
                @auth
                    <span>{{ auth()->user()->alias }}</span>
                    @can('admin.see-menu')
                        <a href="{{ route('admin.dashboard') }}" class="tc-button tc-button--secondary">{{ __('booking.nav.admin') }}</a>
                    @endcan
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">{{ __('booking.nav.logout') }}</x-ui.button>
                    </form>
                @endauth
            </div>
        </header>
        <main class="members-content">
            @foreach(['success', 'status', 'error'] as $message)
                @if(session($message))
                    <div class="ui-flash {{ $message === 'error' ? 'ui-flash-error' : 'ui-flash-success' }}" role="status">{{ session($message) }}</div>
                @endif
            @endforeach
            @if($errors->any())
                <div class="ui-flash ui-flash-error" role="alert">{{ $errors->first() }}</div>
            @endif
            @yield('admin-content')
        </main>
    </div>
</div>
</body>
</html>
