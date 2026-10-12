<?php

use App\Jobs\ModerateChatMessage;
use App\Models\ChatMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('beerandcode - Ao vivo')] class extends Component
{
    private const int MaxMessages = 80;

    private const int MaxDecisions = 6;

    private const int MaxMistakes = 100;

    /**
     * Counts the strikes and how the moderator used them: "strikes_missed" are blocked
     * messages left without a strike and "strikes_undue" are strikes on messages it let through.
     */
    private const string Punishment = 'sum(case when strike then 1 else 0 end) as strikes, '
        .'sum(case when skipped then 1 else 0 end) as skipped, '
        .'sum(case when error is null and blocked and not strike then 1 else 0 end) as strikes_missed, '
        .'sum(case when strike and not blocked then 1 else 0 end) as strikes_undue';

    /**
     * Compares the verdicts with the ones the dataset expects, ignoring failed calls:
     * "missed" should have been blocked and "overblocked" should have gone through.
     */
    private const string Accuracy = 'sum(case when error is null and blocked is not null and expected is not null then 1 else 0 end) as labeled, '
        .'sum(case when error is null and blocked = expected then 1 else 0 end) as correct, '
        .'sum(case when error is null and expected and not blocked then 1 else 0 end) as missed, '
        .'sum(case when error is null and blocked and not expected then 1 else 0 end) as overblocked';

    /**
     * The key of the selected model in config/moderation.php.
     */
    public string $model = '';

    public string $draft = '';

    public int $viewers = 12483;

    /**
     * Whether the modal comparing every model is open.
     */
    public bool $comparing = false;

    public function mount(): void
    {
        $models = config('moderation.models');

        $this->model = array_key_exists(config('moderation.default'), $models) ? config('moderation.default') : array_key_first($models);
    }

    public function selectModel(string $model): void
    {
        abort_unless(array_key_exists($model, config('moderation.models')), 404);

        $this->model = $model;
    }

    /**
     * Switches strikes and bans on or off for every moderator, from the next message on.
     */
    public function toggleStrikes(): void
    {
        Cache::forever(ChatMessage::StrikesSwitch, ! ChatMessage::strikesEnabled());
    }

    /**
     * Shows the message right away and queues its moderation; the screen picks up the verdict by polling.
     */
    public function send(): void
    {
        $this->draft = trim($this->draft);

        if ($this->draft === '') {
            return;
        }

        $this->validate(['draft' => 'string|max:500'], attributes: ['draft' => 'mensagem']);

        $message = ChatMessage::create(['model' => $this->model, 'user' => 'voce', 'text' => $this->draft]);

        ModerateChatMessage::dispatch($message);

        $this->reset('draft');
    }

    /**
     * The latest chat messages, oldest first. The blocked ones are rendered as removed.
     *
     * @return Collection<int, ChatMessage>
     */
    #[Computed]
    public function chatMessages(): Collection
    {
        return ChatMessage::query()
            ->latest('id')
            ->limit(self::MaxMessages)
            ->get()
            ->reverse();
    }

    /**
     * The latest verdicts of the selected model, newest first.
     *
     * @return Collection<int, ChatMessage>
     */
    #[Computed]
    public function history(): Collection
    {
        return ChatMessage::query()
            ->where('model', $this->model)
            ->where('skipped', false)
            ->whereNotNull('moderated_at')
            ->latest('moderated_at')
            ->latest('id')
            ->limit(self::MaxDecisions)
            ->get();
    }

    /**
     * The totals of each model, keyed by model. The average time ignores failed calls.
     *
     * @return Collection<string, object{model: string, total: int, moderated: int, blocked: int, errors: int, average_ms: ?float, input_tokens: int, output_tokens: int, started_at: string, finished_at: ?string, strikes: int, skipped: int, strikes_missed: int, strikes_undue: int, labeled: int, correct: int, missed: int, overblocked: int}>
     */
    #[Computed]
    public function stats(): Collection
    {
        return ChatMessage::query()
            ->toBase()
            ->selectRaw('model, count(*) as total, count(moderated_at) as moderated, sum(case when blocked then 1 else 0 end) as blocked, count(error) as errors, avg(case when error is null then ms end) as average_ms, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, min(created_at) as started_at, max(moderated_at) as finished_at, '.self::Punishment.', '.self::Accuracy)
            ->groupBy('model')
            ->get()
            ->keyBy('model');
    }

    /**
     * The accuracy of each model on each kind of dataset message, keyed by "model|kind".
     *
     * @return Collection<string, object{model: string, kind: string, labeled: int, correct: int, missed: int, overblocked: int}>
     */
    #[Computed]
    public function kindStats(): Collection
    {
        return ChatMessage::query()
            ->toBase()
            ->selectRaw('model, kind, '.self::Accuracy)
            ->whereNotNull('kind')
            ->groupBy('model', 'kind')
            ->get()
            ->keyBy(fn (object $row): string => "{$row->model}|{$row->kind}");
    }

    /**
     * The strikes, the ban and the blocks the dataset expects for each author, keyed by "model|user".
     *
     * @return Collection<string, object{model: string, user: string, strikes: int, banned: int, expected_blocks: int}>
     */
    #[Computed]
    public function authorStats(): Collection
    {
        return ChatMessage::query()
            ->toBase()
            ->select('model', 'user')
            ->selectRaw('sum(case when strike then 1 else 0 end) as strikes, max(case when ban then 1 else 0 end) as banned, sum(case when expected then 1 else 0 end) as expected_blocks')
            ->groupBy('model', 'user')
            ->get()
            ->keyBy(fn (object $row): string => "{$row->model}|{$row->user}");
    }

    /**
     * The messages each model got wrong, grouped by model.
     *
     * @return Collection<string, Collection<int, ChatMessage>>
     */
    #[Computed]
    public function mistakes(): Collection
    {
        return ChatMessage::query()
            ->whereNull('error')
            ->whereColumn('blocked', '!=', 'expected')
            ->oldest('id')
            ->limit(self::MaxMistakes)
            ->get()
            ->groupBy('model');
    }
};
?>

<div wire:poll.1s class="flex h-dvh flex-col overflow-hidden">
    {{-- Top navigation --}}
    <header class="z-10 flex h-[50px] shrink-0 items-center justify-between gap-4 bg-tv-panel px-2.5 shadow-[0_1px_2px_rgba(0,0,0,.9)]">
        <div class="flex items-center gap-5">
            <a href="{{ route('watch') }}" aria-label="Início" class="grid size-8 place-items-center rounded-md bg-tv-purple text-white">
                <x-icon name="play" class="size-4 fill-current" />
            </a>
            <nav class="hidden items-center gap-5 text-lg font-semibold md:flex">
                <a href="#" class="hover:text-tv-link">Seguindo</a>
                <a href="#" class="hover:text-tv-link">Procurar</a>
            </nav>
            <button type="button" aria-label="Mais" class="grid size-[30px] place-items-center rounded hover:bg-white/10">
                <x-icon name="dots" />
            </button>
        </div>

        <div class="hidden w-full max-w-[390px] sm:flex">
            <input type="search" placeholder="Buscar" aria-label="Buscar" class="h-9 min-w-0 flex-1 rounded-l-md border border-white/20 bg-tv-bg px-2.5 text-sm outline-none placeholder:text-tv-muted focus:border-tv-purple">
            <button type="button" aria-label="Buscar" class="grid w-9 place-items-center rounded-r-md bg-white/10 hover:bg-white/15">
                <x-icon name="search" />
            </button>
        </div>

        <div class="flex items-center gap-1.5">
            @foreach (['crown' => 'Recompensas', 'bell' => 'Notificações', 'message' => 'Sussurros'] as $icon => $label)
                <button type="button" aria-label="{{ $label }}" class="hidden size-[30px] place-items-center rounded hover:bg-white/10 sm:grid">
                    <x-icon :name="$icon" />
                </button>
            @endforeach
            <button type="button" class="hidden h-[30px] items-center gap-1.5 rounded bg-white/10 px-2.5 font-semibold hover:bg-white/15 lg:flex">
                <x-icon name="gem" class="size-4" />
                Obter Bits
            </button>
            <span class="ml-1.5 grid size-[30px] place-items-center rounded-full bg-tv-link font-bold text-tv-bg">V</span>
        </div>
    </header>

    <div class="flex min-h-0 flex-1 flex-col md:flex-row">
        {{-- Models sidebar --}}
        <aside class="hidden w-[50px] shrink-0 flex-col overflow-y-auto bg-tv-raised pb-2.5 lg:flex xl:w-60">
            <div class="hidden items-center justify-between p-2.5 xl:flex">
                <h2 class="text-lg font-semibold">Modelos</h2>
                <x-icon name="collapse-left" />
            </div>

            @foreach (['agent' => ['LLM convencional', '#9147ff'], 'decision' => ['Decisions API', '#0ea5a4'], 'moderation' => ['Moderation API', '#d97706']] as $driver => [$heading, $color])
                <h3 class="mt-2.5 hidden px-2.5 py-1.5 font-semibold text-tv-muted uppercase first:mt-0 xl:block">{{ $heading }}</h3>
                @foreach (config('moderation.models') as $key => $option)
                    @continue($option['driver'] !== $driver)
                    @php
                        $totals = $this->stats->get($key);
                        $stat = '—';

                        if ($totals?->average_ms !== null) {
                            $stat = number_format($totals->average_ms, 0, ',', '.').' ms';
                        } elseif ($totals?->errors) {
                            $stat = 'erro';
                        }
                    @endphp
                    <x-model-row
                        :name="$option['label']"
                        :detail="$option['provider'].' · '.$option['model']"
                        :stat="$stat"
                        :color="$color"
                        :active="$key === $model"
                        wire:click="selectModel('{{ $key }}')"
                        wire:key="model-{{ $key }}"
                    />
                @endforeach
            @endforeach

            @php $strikesOn = ChatMessage::strikesEnabled(); @endphp
            <button type="button" wire:click="toggleStrikes" role="switch" aria-checked="{{ $strikesOn ? 'true' : 'false' }}" title="Strikes e banimento" class="mt-2.5 flex w-full items-center gap-2.5 border-t border-white/10 px-2.5 py-2.5 text-left hover:bg-white/5">
                <span @class(['relative h-[18px] w-[30px] shrink-0 rounded-full transition-colors', 'bg-tv-purple' => $strikesOn, 'bg-white/20' => ! $strikesOn])>
                    <span @class(['absolute top-0.5 size-3.5 rounded-full bg-white transition-all', 'left-[14px]' => $strikesOn, 'left-0.5' => ! $strikesOn])></span>
                </span>
                <span class="hidden min-w-0 flex-col leading-tight xl:flex">
                    <span class="text-sm font-semibold">Strikes e ban</span>
                    <span class="truncate text-tv-muted">{{ $strikesOn ? '3 strikes banem o autor' : 'Desligado: só modera' }}</span>
                </span>
            </button>
        </aside>

        {{-- Player + channel info --}}
        <main class="min-w-0 shrink-0 md:flex-1 md:overflow-y-auto">
            <div class="group relative aspect-video max-h-[calc(100dvh-13rem)] w-full overflow-hidden bg-black">
                @php
                    $selected = config("moderation.models.{$model}");
                    $history = $this->history;
                    $decision = $history->first();
                    $totals = $this->stats->get($model);
                    $moderated = $totals->moderated ?? 0;
                    $pending = ($totals->total ?? 0) - $moderated;
                    $answered = $moderated - ($totals->errors ?? 0) - ($totals->skipped ?? 0);
                    $cost = fn (array $option, int $inputTokens, int $outputTokens): float => ($inputTokens * $option['input_price'] + $outputTokens * $option['output_price']) / 1_000_000;
                    $totalCost = $cost($selected, (int) ($totals->input_tokens ?? 0), (int) ($totals->output_tokens ?? 0));
                    $queueTime = function (?object $row): string {
                        if (! $row?->finished_at) {
                            return '—';
                        }

                        $seconds = (int) Carbon::parse($row->started_at)->diffInSeconds(Carbon::parse($row->finished_at));

                        return $seconds < 60 ? $seconds.' s' : intdiv($seconds, 60).' min '.($seconds % 60).' s';
                    };
                @endphp
                <div class="absolute inset-0 flex flex-col gap-3 overflow-hidden bg-[#1b1b29] p-4 pt-11 sm:p-6 sm:pt-12">
                    <div class="hidden flex-wrap items-center gap-x-4 gap-y-1 font-mono text-xs text-tv-muted sm:flex">
                        <span class="font-semibold text-tv-link">{{ $selected['label'] }}</span>
                        <span>driver: <span class="text-tv-text">{{ $selected['driver'] }}</span></span>
                        <span>provider: <span class="text-tv-text">{{ $selected['provider'] }}</span></span>
                        <span>modelo: <span class="text-tv-text">{{ $selected['model'] }}</span></span>
                        <span>moderadas: <span class="text-tv-text tabular-nums">{{ $moderated }}</span></span>
                        <span>bloqueadas: <span class="text-tv-text tabular-nums">{{ $totals->blocked ?? 0 }}</span></span>
                        @if ($pending > 0)
                            <span class="animate-pulse font-semibold text-[#ffd37a] tabular-nums">{{ $pending }} na fila…</span>
                        @endif
                    </div>

                    @if ($decision)
                        <div class="flex items-center gap-2.5">
                            @if ($decision->error)
                                <span class="shrink-0 rounded bg-[#ffd37a] px-1.5 py-0.5 font-semibold text-tv-bg uppercase">Indisponível</span>
                            @elseif ($decision->blocked)
                                <span class="shrink-0 rounded bg-tv-red px-1.5 py-0.5 font-semibold uppercase">Bloqueada</span>
                            @else
                                <span class="shrink-0 rounded bg-[#00ad03] px-1.5 py-0.5 font-semibold uppercase">Liberada</span>
                            @endif
                            @if ($decision->guideline)
                                <span class="shrink-0 rounded bg-white/10 px-1.5 py-0.5 font-semibold">{{ config("moderation.guidelines.{$decision->guideline}.label", $decision->guideline) }}</span>
                            @endif
                            @if ($decision->strike)
                                <span class="shrink-0 rounded bg-tv-red/25 px-1.5 py-0.5 font-semibold text-[#ff8280]">{{ $selected['driver'] === 'agent' ? 'tool: strike' : 'regra: strike' }}</span>
                            @endif
                            @if ($decision->ban)
                                <span class="shrink-0 rounded bg-tv-red px-1.5 py-0.5 font-semibold">{{ $selected['driver'] === 'agent' ? 'tool: ban' : 'regra: ban' }}</span>
                            @endif
                            <p class="truncate text-base font-semibold">“{{ $decision->text }}”</p>
                        </div>

                        <div class="text-sm">
                            <h2 class="font-mono text-xs text-tv-muted uppercase">Raciocínio</h2>
                            @if ($decision->error)
                                <p class="line-clamp-3 text-[#ffd37a]">O moderador falhou e a mensagem foi liberada sem análise: {{ $decision->error }}</p>
                            @elseif ($decision->reason)
                                <p class="line-clamp-3 sm:line-clamp-4">{{ $decision->reason }}</p>
                            @else
                                <p class="text-tv-muted">Este modelo não devolve raciocínio, só a probabilidade da categoria mais provável.</p>
                            @endif
                        </div>

                        <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            @foreach ([
                                'Tempo total' => number_format($decision->ms, 0, ',', '.').' ms',
                                'Tokens (entrada → saída)' => $decision->input_tokens + $decision->output_tokens > 0 ? $decision->input_tokens.' → '.$decision->output_tokens : '—',
                                'Prob. de violação' => $decision->probability === null ? '—' : round($decision->probability * 100).'%',
                                'Média ('.$answered.')' => $answered > 0 ? number_format($totals->average_ms, 0, ',', '.').' ms' : '—',
                                'Custo da mensagem' => $decision->input_tokens + $decision->output_tokens > 0 ? 'US$ '.number_format($cost($selected, $decision->input_tokens, $decision->output_tokens), 6, ',', '.') : '—',
                                'Custo total ('.$answered.')' => $totalCost > 0 ? 'US$ '.number_format($totalCost, 4, ',', '.') : '—',
                                'Tempo da fila ('.$moderated.')' => $queueTime($totals),
                                'Bloqueadas' => ($totals->blocked ?? 0).' de '.$answered,
                            ] as $label => $value)
                                <div class="rounded bg-black/25 px-3 py-2">
                                    <dt class="truncate font-mono text-[11px] text-tv-muted uppercase">{{ $label }}</dt>
                                    <dd class="text-lg font-semibold tabular-nums">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>

                        <ol class="hidden w-3/4 flex-col gap-1 font-mono text-xs lg:flex">
                            @foreach ($history->skip(1) as $previous)
                                <li wire:key="decision-{{ $previous->id }}" class="flex items-center gap-3 text-tv-muted">
                                    <span class="w-16 shrink-0 text-right tabular-nums">{{ number_format($previous->ms, 0, ',', '.') }} ms</span>
                                    <span @class(['w-20 shrink-0 font-semibold', 'text-[#ffd37a]' => $previous->error, 'text-[#ff8280]' => $previous->blocked, 'text-[#5fdd7c]' => ! $previous->blocked && ! $previous->error])>{{ $previous->error ? 'indisponível' : ($previous->blocked ? 'bloqueada' : 'liberada') }}</span>
                                    <span class="truncate">{{ $previous->text }}</span>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="m-auto max-w-sm text-center text-sm text-tv-muted">Envie uma mensagem no chat para ver aqui a decisão do {{ $selected['label'] }}, o raciocínio e o tempo de resposta.</p>
                    @endif

                    <div class="absolute right-[2.5%] bottom-[5%] hidden aspect-video w-1/6 place-items-center rounded-lg border-2 border-tv-purple bg-linear-to-br from-[#2a1a4a] to-[#12121a] xl:grid" aria-hidden="true">
                        <span class="grid aspect-square w-1/3 place-items-center rounded-full bg-tv-purple font-bold">B</span>
                    </div>
                </div>

                <span class="absolute top-2.5 left-2.5 rounded bg-tv-red px-1.5 py-0.5 font-semibold uppercase">Ao vivo</span>
                <button type="button" wire:click="$set('comparing', true)" class="absolute top-2 right-2.5 rounded bg-tv-purple px-2.5 py-1 font-semibold hover:bg-tv-purple-hover">Comparar modelos</button>

                <div class="absolute inset-x-0 bottom-0 flex items-center justify-between bg-linear-to-t from-black/80 to-transparent px-2.5 pt-8 pb-2 opacity-0 transition-opacity group-hover:opacity-100">
                    <div class="flex items-center gap-1">
                        @foreach (['pause' => 'Pausar', 'volume' => 'Volume'] as $icon => $label)
                            <button type="button" aria-label="{{ $label }}" class="grid size-[30px] place-items-center rounded hover:bg-white/15"><x-icon :name="$icon" /></button>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-1">
                        @foreach (['cog' => 'Configurações', 'theatre' => 'Modo teatro', 'fullscreen' => 'Tela cheia'] as $icon => $label)
                            <button type="button" aria-label="{{ $label }}" class="grid size-[30px] place-items-center rounded hover:bg-white/15"><x-icon :name="$icon" /></button>
                        @endforeach
                    </div>
                </div>
            </div>

            <section class="hidden flex-wrap items-start gap-3 px-5 py-4 md:flex">
                <div class="relative shrink-0">
                    <span class="grid size-16 place-items-center rounded-full bg-tv-purple text-2xl font-bold ring-2 ring-tv-purple ring-offset-2 ring-offset-tv-bg">B</span>
                    <span class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 rounded bg-tv-red px-1 text-[11px] font-semibold whitespace-nowrap uppercase">Ao vivo</span>
                </div>

                <div class="min-w-0 flex-1">
                    <h1 class="flex items-center gap-1.5 text-xl font-semibold">
                        beerandcode
                        <span class="grid size-4 place-items-center rounded-full bg-tv-purple" title="Verificado"><x-icon name="check" class="size-3" /></span>
                    </h1>
                    <p class="truncate text-sm font-semibold">Clonando a tela de uma live com Laravel + Livewire 4 + Blaze 🍺</p>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <a href="#" class="text-sm text-tv-link hover:underline">Software e desenvolvimento</a>
                        @foreach (['Português', 'Laravel', 'Livewire', 'IA'] as $tag)
                            <span class="rounded-full bg-white/10 px-2 py-0.5 text-xs font-semibold text-tv-muted">{{ $tag }}</span>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-col items-end gap-2.5">
                    <div class="flex items-center gap-2.5">
                        <button type="button" class="flex h-[30px] items-center gap-1.5 rounded bg-tv-purple px-2.5 font-semibold hover:bg-tv-purple-hover">
                            <x-icon name="heart" class="size-4" />
                            Seguir
                        </button>
                        <button type="button" class="flex h-[30px] items-center gap-1.5 rounded bg-white/10 px-2.5 font-semibold hover:bg-white/15">
                            <x-icon name="star" class="size-4" />
                            Inscrever-se
                            <x-icon name="chevron-down" class="size-4" />
                        </button>
                    </div>
                    <div class="flex items-center gap-3 font-semibold">
                        <span class="flex items-center gap-1 text-[#ff8280]" title="Espectadores">
                            <x-icon name="user" class="size-4" />
                            {{ number_format($viewers, 0, ',', '.') }}
                        </span>
                        <span
                            class="tabular-nums"
                            title="Tempo ao vivo"
                            x-data="{ seconds: 5025 }"
                            x-init="setInterval(() => seconds++, 1000)"
                            x-text="[seconds / 3600, seconds / 60 % 60, seconds % 60].map((part, index) => String(Math.floor(part)).padStart(index ? 2 : 1, '0')).join(':')"
                        >1:23:45</span>
                        <button type="button" aria-label="Compartilhar" class="grid size-[30px] place-items-center rounded hover:bg-white/10"><x-icon name="share" /></button>
                        <button type="button" aria-label="Mais" class="grid size-[30px] place-items-center rounded hover:bg-white/10"><x-icon name="dots" /></button>
                    </div>
                </div>
            </section>

            <section class="mx-5 mb-8 hidden rounded-md bg-tv-panel p-6 md:block">
                <h2 class="text-xl font-semibold">Sobre beerandcode</h2>
                <p class="mt-1 text-sm text-tv-muted"><strong class="text-tv-text">48,2 mil</strong> seguidores</p>
                <p class="mt-3 max-w-prose text-sm">Lives de engenharia de software e IA, sempre com código de verdade na tela. Pega sua bebida e cola no chat.</p>
            </section>
        </main>

        {{-- Stream chat --}}
        <aside x-data="{ pinned: true }" class="flex min-h-0 w-full flex-1 flex-col border-white/10 bg-tv-panel md:w-[340px] md:flex-none md:border-l">
            <header class="flex h-[50px] shrink-0 items-center justify-between border-b border-white/10 px-2.5">
                <button type="button" aria-label="Recolher chat" class="grid size-[30px] place-items-center rounded hover:bg-white/10"><x-icon name="collapse-right" /></button>
                <h2 class="text-xs font-semibold uppercase">Chat da transmissão</h2>
                <button type="button" aria-label="Comunidade" class="grid size-[30px] place-items-center rounded hover:bg-white/10"><x-icon name="users" /></button>
            </header>

            <div class="relative min-h-0 flex-1">
                <div
                    x-ref="messages"
                    x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => pinned && ($el.scrollTop = $el.scrollHeight)).observe($el, { childList: true })"
                    x-on:scroll="pinned = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 60"
                    x-on:resize.window="pinned && ($el.scrollTop = $el.scrollHeight)"
                    class="h-full overflow-y-auto py-2.5"
                    role="log"
                    aria-label="Mensagens do chat"
                >
                    <p class="px-5 py-[5px] text-tv-muted">Bem-vindo ao chat!</p>
                    @foreach ($this->chatMessages as $chatMessage)
                        @php
                            $isViewer = $chatMessage->user === 'voce';
                            $author = $this->authorStats->get($chatMessage->model.'|'.$chatMessage->user);
                            $tag = $author?->banned ? 'banido' : ($author?->strikes ? $author->strikes.'/'.ChatMessage::StrikeLimit : null);
                            $notice = $chatMessage->skipped ? 'mensagem removida: usuário banido' : ($chatMessage->blocked ? 'mensagem removida pela moderação' : null);
                        @endphp
                        <x-chat-message :user="$chatMessage->user" :color="$isViewer ? '#bf94ff' : 'hsl('.(crc32($chatMessage->user) % 360).' 70% 70%)'" :text="$chatMessage->text" :badge="$isViewer ? 'sub' : null" :tag="$tag" :notice="$notice" wire:key="message-{{ $chatMessage->id }}" />
                    @endforeach
                </div>

                <button
                    type="button"
                    x-cloak
                    x-show="! pinned"
                    x-on:click="pinned = true; $refs.messages.scrollTop = $refs.messages.scrollHeight"
                    class="absolute bottom-2.5 left-1/2 -translate-x-1/2 rounded bg-black/75 px-3 py-1.5 font-semibold whitespace-nowrap hover:bg-black"
                >Chat pausado devido à rolagem</button>
            </div>

            <form wire:submit="send" x-on:submit="pinned = true" class="flex shrink-0 flex-col gap-2.5 p-2.5">
                <div class="relative">
                    <input type="text" wire:model="draft" maxlength="500" autocomplete="off" placeholder="Enviar uma mensagem" aria-label="Enviar uma mensagem" class="h-10 w-full rounded bg-white/10 pr-9 pl-2.5 ring-tv-purple outline-none placeholder:text-tv-muted focus:bg-black focus:ring-2">
                    <x-icon name="smile" class="absolute top-2.5 right-2 text-tv-muted" />
                </div>
                @error('draft')
                    <p class="text-[#ff8280]">{{ $message }}</p>
                @enderror
                <div class="flex items-center justify-between">
                    <span class="flex items-center gap-1.5 font-semibold" title="Pontos do canal">
                        <x-icon name="gem" class="size-4 text-tv-link" />
                        1.250
                    </span>
                    <div class="flex items-center gap-1.5">
                        <button type="button" aria-label="Configurações do chat" class="grid size-[30px] place-items-center rounded hover:bg-white/10"><x-icon name="cog" /></button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="send" class="h-[30px] rounded bg-tv-purple px-2.5 font-semibold hover:bg-tv-purple-hover disabled:opacity-50">Chat</button>
                    </div>
                </div>
            </form>
        </aside>
    </div>

    {{-- Model comparison --}}
    @if ($comparing)
        @php
            $drivers = ['agent' => 'LLM', 'decision' => 'Decisions API', 'moderation' => 'Moderation API'];
            $kinds = ['claro' => 'Claro', 'ortografia' => 'Ortografia', 'limiar' => 'Limiar', 'misto' => 'Misto', 'adversarial' => 'Adversarial'];
            $ratio = fn (int $part, int $whole): string => $whole > 0 ? $part.' de '.$whole.' ('.number_format($part / $whole * 100, 1, ',', '.').'%)' : '—';
            $ran = collect(config('moderation.models'))->filter(fn (array $option, string $key): bool => $this->stats->has($key));
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 p-4" x-data x-on:keydown.escape.window="$wire.set('comparing', false)" role="dialog" aria-modal="true" aria-label="Comparação dos modelos">
            <div class="flex max-h-full w-full max-w-6xl flex-col rounded-md bg-tv-panel shadow-2xl">
                <header class="flex shrink-0 items-center justify-between border-b border-white/10 px-5 py-3">
                    <h2 class="text-lg font-semibold">Comparação dos modelos</h2>
                    <button type="button" wire:click="$set('comparing', false)" class="rounded bg-white/10 px-2.5 py-1 font-semibold hover:bg-white/15">Fechar</button>
                </header>

                <div class="flex min-h-0 flex-col gap-6 overflow-y-auto p-5">
                    @if ($ran->isEmpty())
                        <p class="text-tv-muted">Nenhum modelo rodou ainda. Envie mensagens no chat ou rode <code class="font-mono">php artisan chat:replay</code>.</p>
                    @else
                        <section class="shrink-0 overflow-x-auto">
                            <h3 class="mb-2 font-mono text-xs text-tv-muted uppercase">Resumo</h3>
                            <table class="w-full text-left text-sm whitespace-nowrap tabular-nums">
                                <thead class="font-mono text-[11px] text-tv-muted uppercase">
                                    <tr>
                                        @foreach (['Modelo', 'Moderadas', 'Acerto', 'Deixou passar', 'Bloqueou à toa', 'Indisponível', 'Tempo médio', 'Tempo da fila', 'Custo total', 'Custo / mil'] as $heading)
                                            <th class="px-2.5 py-1.5 font-normal">{{ $heading }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($ran as $key => $option)
                                        @php
                                            $row = $this->stats->get($key);
                                            $rowCost = $cost($option, (int) $row->input_tokens, (int) $row->output_tokens);
                                            $rowAnswered = $row->moderated - $row->errors - $row->skipped;
                                        @endphp
                                        <tr wire:key="summary-{{ $key }}" class="border-t border-white/10">
                                            <td class="px-2.5 py-1.5">
                                                <span class="font-semibold">{{ $option['label'] }}</span>
                                                <span class="text-tv-muted">{{ $drivers[$option['driver']] }}</span>
                                            </td>
                                            <td class="px-2.5 py-1.5">{{ $row->moderated }}@if ($row->total > $row->moderated) <span class="text-[#ffd37a]">+{{ $row->total - $row->moderated }} na fila</span>@endif</td>
                                            <td class="px-2.5 py-1.5 font-semibold">{{ $ratio((int) $row->correct, (int) $row->labeled) }}</td>
                                            <td class="px-2.5 py-1.5 text-[#ff8280]">{{ $row->labeled ? $row->missed : '—' }}</td>
                                            <td class="px-2.5 py-1.5 text-[#ffd37a]">{{ $row->labeled ? $row->overblocked : '—' }}</td>
                                            <td class="px-2.5 py-1.5">{{ $row->errors }}</td>
                                            <td class="px-2.5 py-1.5">{{ $row->average_ms === null ? '—' : number_format($row->average_ms, 0, ',', '.').' ms' }}</td>
                                            <td class="px-2.5 py-1.5">{{ $queueTime($row) }}</td>
                                            <td class="px-2.5 py-1.5">{{ $rowCost > 0 ? 'US$ '.number_format($rowCost, 4, ',', '.') : '—' }}</td>
                                            <td class="px-2.5 py-1.5">{{ $rowCost > 0 ? 'US$ '.number_format($rowCost / $rowAnswered * 1000, 4, ',', '.') : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </section>

                        <section class="shrink-0 overflow-x-auto">
                            <h3 class="mb-2 font-mono text-xs text-tv-muted uppercase">Acerto por tipo de mensagem</h3>
                            <table class="w-full text-left text-sm whitespace-nowrap tabular-nums">
                                <thead class="font-mono text-[11px] text-tv-muted uppercase">
                                    <tr>
                                        <th class="px-2.5 py-1.5 font-normal">Tipo</th>
                                        @foreach ($ran as $option)
                                            <th class="px-2.5 py-1.5 font-normal">{{ $option['label'] }} · {{ $drivers[$option['driver']] }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($kinds as $kind => $label)
                                        <tr wire:key="kind-{{ $kind }}" class="border-t border-white/10">
                                            <td class="px-2.5 py-1.5 font-semibold">{{ $label }}</td>
                                            @foreach ($ran as $key => $option)
                                                @php $cell = $this->kindStats->get($key.'|'.$kind); @endphp
                                                <td class="px-2.5 py-1.5">{{ $ratio((int) ($cell->correct ?? 0), (int) ($cell->labeled ?? 0)) }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </section>

                        <section class="shrink-0 overflow-x-auto">
                            <h3 class="mb-2 font-mono text-xs text-tv-muted uppercase">Strikes e banimentos · LLM usa tools, Decisions usa regra no código</h3>
                            <table class="w-full text-left text-sm whitespace-nowrap tabular-nums">
                                <thead class="font-mono text-[11px] text-tv-muted uppercase">
                                    <tr>
                                        @foreach (['Modelo', 'Strikes', 'Strike esquecido', 'Strike indevido', 'Banidos', 'Banidos certos', 'Ban indevido', 'Ban esquecido', 'Puladas por ban'] as $heading)
                                            <th class="px-2.5 py-1.5 font-normal">{{ $heading }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($ran as $key => $option)
                                        @php
                                            $row = $this->stats->get($key);
                                            $authors = $this->authorStats->where('model', $key);
                                            $banned = $authors->where('banned', 1);
                                            $expectedBans = $authors->where('expected_blocks', '>=', ChatMessage::StrikeLimit);
                                        @endphp
                                        <tr wire:key="punishment-{{ $key }}" class="border-t border-white/10">
                                            <td class="px-2.5 py-1.5">
                                                <span class="font-semibold">{{ $option['label'] }}</span>
                                                <span class="text-tv-muted">{{ $option['driver'] === 'agent' ? 'tools' : 'regra' }}</span>
                                            </td>
                                            <td class="px-2.5 py-1.5">{{ $row->strikes }}</td>
                                            <td class="px-2.5 py-1.5 text-[#ff8280]">{{ $row->strikes_missed }}</td>
                                            <td class="px-2.5 py-1.5 text-[#ffd37a]">{{ $row->strikes_undue }}</td>
                                            <td class="px-2.5 py-1.5">{{ $banned->count() }}</td>
                                            <td class="px-2.5 py-1.5 font-semibold">{{ $banned->where('expected_blocks', '>=', ChatMessage::StrikeLimit)->count() }} de {{ $expectedBans->count() }}</td>
                                            <td class="px-2.5 py-1.5 text-[#ffd37a]">{{ $banned->where('expected_blocks', '<', ChatMessage::StrikeLimit)->count() }}</td>
                                            <td class="px-2.5 py-1.5 text-[#ff8280]">{{ $authors->where('banned', 0)->where('strikes', '>=', ChatMessage::StrikeLimit)->count() }}</td>
                                            <td class="px-2.5 py-1.5">{{ $row->skipped }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </section>

                        <section>
                            <h3 class="mb-2 font-mono text-xs text-tv-muted uppercase">Erros de cada modelo</h3>
                            @forelse ($this->mistakes as $key => $mistakes)
                                <h4 class="mt-3 mb-1 font-semibold first:mt-0">{{ config("moderation.models.{$key}.label", $key) }} <span class="text-tv-muted">· {{ $mistakes->count() }}</span></h4>
                                <ul class="flex flex-col gap-1 text-sm">
                                    @foreach ($mistakes as $mistake)
                                        <li wire:key="mistake-{{ $mistake->id }}" class="flex items-baseline gap-3">
                                            <span @class(['w-28 shrink-0 font-mono text-xs font-semibold', 'text-[#ff8280]' => $mistake->expected, 'text-[#ffd37a]' => ! $mistake->expected])>{{ $mistake->expected ? 'deixou passar' : 'bloqueou à toa' }}</span>
                                            <span class="w-20 shrink-0 font-mono text-xs text-tv-muted">{{ $mistake->kind }}</span>
                                            <span class="w-10 shrink-0 text-right font-mono text-xs text-tv-muted tabular-nums">{{ $mistake->probability === null ? '' : round($mistake->probability * 100).'%' }}</span>
                                            <span class="min-w-0 wrap-break-word">{{ $mistake->text }}@if ($mistake->guideline) <span class="rounded bg-white/10 px-1 text-xs">{{ config("moderation.guidelines.{$mistake->guideline}.label", $mistake->guideline) }}</span>@endif @if ($mistake->reason) <span class="text-tv-muted">— {{ $mistake->reason }}</span>@endif</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @empty
                                <p class="text-tv-muted">Nenhum erro nas mensagens com gabarito. Só as mensagens do <code class="font-mono">chat:replay</code> têm gabarito.</p>
                            @endforelse
                        </section>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
