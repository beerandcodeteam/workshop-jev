@props(['name'])

@php
    $paths = [
        'bell' => 'M5 14V9a5 5 0 0 1 10 0v5l1.5 2h-13zM8.5 17.5h3',
        'check' => 'M5 10.5l3 3 7-7',
        'chevron-down' => 'M5 8l5 5 5-5',
        'cog' => 'M10 12.5a2.5 2.5 0 1 0 0-5a2.5 2.5 0 0 0 0 5zM10 2.5v2M10 15.5v2M2.5 10h2M15.5 10h2M4.7 4.7l1.4 1.4M13.9 13.9l1.4 1.4M4.7 15.3l1.4-1.4M13.9 6.1l1.4-1.4',
        'collapse-left' => 'M12 5l-5 5 5 5M16 4v12',
        'collapse-right' => 'M8 5l5 5-5 5M4 4v12',
        'crown' => 'M3 15h14M3 12l1-7 4 4 2-5 2 5 4-4 1 7z',
        'dots' => 'M10 4.5v.01M10 10v.01M10 15.5v.01',
        'fullscreen' => 'M3 7V3h4M13 3h4v4M17 13v4h-4M7 17H3v-4',
        'gem' => 'M10 2.5l6 6-6 9-6-9z',
        'heart' => 'M10 17s-7-4.2-7-9a4 4 0 0 1 7-2.5A4 4 0 0 1 17 8c0 4.8-7 9-7 9z',
        'message' => 'M3 4h14v10H8l-4 3v-3H3z',
        'pause' => 'M6 4v12M14 4v12',
        'play' => 'M6 4l10 6-10 6z',
        'search' => 'M9 3a6 6 0 1 0 0 12a6 6 0 0 0 0-12zM14 14l4 4',
        'share' => 'M10 13V3M6 6.5L10 3l4 3.5M4 11v6h12v-6',
        'smile' => 'M10 17.5a7.5 7.5 0 1 0 0-15a7.5 7.5 0 0 0 0 15zM7 12a3.5 3.5 0 0 0 6 0M7.5 8v.01M12.5 8v.01',
        'star' => 'M10 2.5l2.3 4.9 5.2.7-3.8 3.7.9 5.3-4.6-2.5-4.6 2.5.9-5.3L2.5 8.1l5.2-.7z',
        'theatre' => 'M2.5 5h15v10h-15z',
        'user' => 'M10 10a3.5 3.5 0 1 0 0-7a3.5 3.5 0 0 0 0 7zM3.5 17a6.5 6.5 0 0 1 13 0',
        'users' => 'M7 9a2.5 2.5 0 1 0 0-5a2.5 2.5 0 0 0 0 5zM13.5 9a2 2 0 1 0 0-4a2 2 0 0 0 0 4zM2.5 16a4.5 4.5 0 0 1 9 0M13 12a3.5 3.5 0 0 1 4.5 3.5',
        'volume' => 'M3 8v4h3l4 3V5L6 8zM13.5 7.5a3.5 3.5 0 0 1 0 5',
    ];
@endphp

<svg {{ $attributes->class('size-5 shrink-0') }} viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="{{ $paths[$name] }}" />
</svg>
