@props(['name', 'detail', 'stat', 'color', 'active' => false])

<button type="button" {{ $attributes->class(['flex w-full items-center gap-2.5 px-2.5 py-[5px] text-left hover:bg-white/5', 'bg-white/10' => $active]) }} title="{{ $name }}" aria-pressed="{{ $active ? 'true' : 'false' }}">
    <span class="grid size-[30px] shrink-0 place-items-center rounded-full font-bold text-white uppercase" style="background: {{ $color }}">{{ $name[0] }}</span>
    <span class="hidden min-w-0 flex-1 flex-col leading-tight xl:flex">
        <span class="truncate text-sm font-semibold">{{ $name }}</span>
        <span class="truncate text-tv-muted">{{ $detail }}</span>
    </span>
    <span class="hidden items-center gap-1.5 self-start tabular-nums xl:flex">
        @if ($active)
            <span class="size-2 rounded-full bg-tv-red"></span>
        @endif
        {{ $stat }}
    </span>
</button>
