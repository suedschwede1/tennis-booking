<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('booking.design.title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="tc-design">
    <main class="tc-page" data-testid="design-preview">
        <h1 class="tc-title">{{ __('booking.design.title') }}</h1>
        <p class="tc-muted">{{ __('booking.design.intro') }}</p>
        <x-ui.card :heading="__('booking.design.controls')">
            <div class="tc-stack">
                <x-ui.field name="player" :label="__('booking.design.player')" :value="__('booking.design.example_player')" />
                <x-ui.field name="partner" :label="__('booking.design.partner')" :error="__('booking.design.required')" />
                <div class="tc-row">
                    <x-ui.badge tone="good">{{ __('booking.design.active') }}</x-ui.badge>
                    <x-ui.badge tone="attention">{{ __('booking.design.pending') }}</x-ui.badge>
                    <x-ui.badge tone="problem">{{ __('booking.design.cancelled') }}</x-ui.badge>
                </div>
                <div x-data="{}">
                    <div class="tc-row">
                        <x-ui.button x-ref="trigger" @click="$refs.dialog.showModal()" data-testid="preview-open">{{ __('booking.design.book') }}</x-ui.button>
                        <x-ui.button variant="secondary">{{ __('booking.design.details') }}</x-ui.button>
                        <x-ui.button variant="danger" disabled>{{ __('booking.design.cancel') }}</x-ui.button>
                    </div>
                    <x-ui.dialog id="preview-dialog" x-ref="dialog" data-testid="preview-dialog"
                        :heading="__('booking.design.confirm')" :close-label="__('booking.design.close')">
                        <p class="tc-muted">{{ __('booking.design.example_slot') }}</p>
                    </x-ui.dialog>
                </div>
            </div>
        </x-ui.card>
    </main>
</body>
</html>
