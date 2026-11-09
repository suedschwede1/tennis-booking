@props(['id', 'heading', 'closeLabel'])
<dialog id="{{ $id }}" aria-labelledby="{{ $id }}-title" {{ $attributes->class(['tc-card', 'tc-dialog']) }}>
    <h2 id="{{ $id }}-title" class="tc-card__title">{{ $heading }}</h2>
    {{ $slot }}
    <form method="dialog">
        <x-ui.button type="submit" variant="secondary">{{ $closeLabel }}</x-ui.button>
    </form>
</dialog>
