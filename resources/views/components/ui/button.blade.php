@props(['variant' => 'primary', 'type' => 'button'])
@php($variant = in_array($variant, ['primary', 'secondary', 'danger'], true) ? $variant : 'secondary')
<button type="{{ $type }}" {{ $attributes->class(['tc-button', 'tc-button--'.$variant]) }}>{{ $slot }}</button>
