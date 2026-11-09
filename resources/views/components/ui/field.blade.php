@props(['name', 'label', 'type' => 'text', 'error' => null])
<div class="tc-field">
    <label for="{{ $name }}" class="tc-label">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
        @if($error) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
        {{ $attributes->class(['tc-input']) }}>
    @if($error)
        <p id="{{ $name }}-error" class="tc-error" role="alert">{{ $error }}</p>
    @endif
</div>
