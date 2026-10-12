@props(['user', 'color', 'text', 'badge' => null, 'tag' => null, 'notice' => null])

@php
    $badges = [
        'mod' => ['bg-[#00ad03]', 'check'],
        'sub' => ['bg-tv-purple', 'star'],
        'vip' => ['bg-[#e005b9]', 'gem'],
    ];
@endphp

<div {{ $attributes->class('px-5 py-[5px] leading-5 wrap-break-word hover:bg-white/5') }}>
    @if ($badge)
        <span class="{{ $badges[$badge][0] }} mr-0.5 inline-grid size-[18px] place-items-center rounded-sm align-middle text-white" title="{{ $badge }}">
            <x-icon :name="$badges[$badge][1]" class="size-3" />
        </span>
    @endif
    <span class="font-bold" style="color: {{ $color }}">{{ $user }}</span>@if ($tag) <span class="rounded bg-tv-red/25 px-1 text-[11px] font-semibold text-[#ff8280]">{{ $tag }}</span>@endif<span>: </span>@if ($notice)<span class="text-tv-muted italic">{{ $notice }}</span>@else<span>{{ $text }}</span>@endif
</div>
