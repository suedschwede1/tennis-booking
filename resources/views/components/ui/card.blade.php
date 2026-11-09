@props(['heading'])
<section {{ $attributes->class(['tc-card']) }}>
    <h2 class="tc-card__title">{{ $heading }}</h2>
    {{ $slot }}
</section>
