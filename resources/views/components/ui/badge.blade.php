@props(['tone' => 'neutral'])
@php($tone = in_array($tone, ['neutral', 'good', 'attention', 'problem'], true) ? $tone : 'neutral')
<span {{ $attributes->class(['tc-badge', 'tc-badge--'.$tone]) }}>{{ $slot }}</span>
