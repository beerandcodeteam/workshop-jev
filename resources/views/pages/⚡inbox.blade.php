<?php

use App\Ai\TicketTriage;
use App\Jobs\TriageTicket;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('beerandcode - Inbox')] class extends Component
{
    private const int MaxTickets = 200;

    /**
     * The key of the selected model in config/moderation.php.
     */
    public string $model = '';

    /**
     * The team confidence, as a percentage, below which a ticket goes to human review.
     */
    public int $reviewBelow = 0;

    /**
     * How much urgency weighs in the priority, as a percentage; frustration weighs the rest.
     */
    public int $urgencyWeight = 0;

    /**
     * The ticket whose answers are open, if any.
     */
    public ?int $inspecting = null;

    public string $draft = '';

    /**
     * The persona a typed ticket comes from; empty sends the ticket without a profile.
     */
    public string $persona = '';

    public function mount(): void
    {
        $this->model = TicketTriage::defaultModel();
        $this->reviewBelow = (int) config('triage.review_below');
        $this->urgencyWeight = (int) config('triage.urgency_weight');
    }

    /**
     * Keeps the board rules inside the range of their sliders.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['reviewBelow', 'urgencyWeight'], true)) {
            $this->{$property} = max(0, min(100, $this->{$property}));
        }
    }

    public function selectModel(string $model): void
    {
        abort_unless(array_key_exists($model, TicketTriage::models()), 404);

        $this->model = $model;
        $this->inspecting = null;
    }

    /**
     * Shows the ticket in the entry column right away and queues its triage; the board picks up the answers by polling.
     */
    public function send(): void
    {
        $this->draft = trim($this->draft);

        if ($this->draft === '') {
            return;
        }

        $this->validate([
            'draft' => 'string|max:1000',
            'persona' => ['nullable', Rule::in(config('triage.personas'))],
        ], attributes: ['draft' => 'ticket', 'persona' => 'cliente']);

        $ticket = Ticket::create([
            'model' => $this->model,
            'customer' => $this->persona ?: 'voce',
            'text' => $this->draft,
            'context' => $this->persona ? TicketTriage::profile($this->persona) : null,
        ]);

        TriageTicket::dispatch($ticket);

        $this->reset('draft');
    }

    public function inspect(int $ticket): void
    {
        abort_unless(Ticket::query()->where('model', $this->model)->whereKey($ticket)->exists(), 404);

        $this->inspecting = $ticket;
    }

    /**
     * The latest tickets of the selected model.
     *
     * @return Collection<int, Ticket>
     */
    #[Computed]
    public function tickets(): Collection
    {
        return Ticket::query()
            ->where('model', $this->model)
            ->latest('id')
            ->limit(self::MaxTickets)
            ->get();
    }

    /**
     * The tickets of each board column, keyed by column. The rules run here, in plain code, over the
     * stored answers: the confidence cut decides who goes to review and the weights decide the order,
     * so changing either one never asks the model again.
     *
     * @return array<string, Collection<int, Ticket>>
     */
    #[Computed]
    public function board(): array
    {
        $columns = array_fill_keys([Ticket::Waiting, ...array_keys(config('triage.teams')), Ticket::Review], []);

        foreach ($this->tickets as $ticket) {
            $column = $ticket->column($this->reviewBelow);

            $columns[array_key_exists($column, $columns) ? $column : Ticket::Review][] = $ticket;
        }

        $board = [];

        foreach ($columns as $column => $tickets) {
            $board[$column] = $column === Ticket::Waiting
                ? collect($tickets)->sortBy('id')->values()
                : collect($tickets)->sortByDesc(fn (Ticket $ticket): float => $ticket->priority($this->urgencyWeight))->values();
        }

        return $board;
    }

    /**
     * The totals of the selected model. "automatic" are the answered tickets the board routed without a person,
     * and the accuracy compares the chosen team with the one the dataset expects.
     *
     * @return array{triaged: int, pending: int, errors: int, answered: int, average_ms: ?float, cost: float, labeled: int, correct: int, automatic: int, automatic_labeled: int, automatic_correct: int}
     */
    #[Computed]
    public function stats(): array
    {
        $tickets = $this->tickets;
        $answered = $tickets->filter(fn (Ticket $ticket): bool => $ticket->triaged_at !== null && $ticket->error === null);
        $automatic = $answered->filter(fn (Ticket $ticket): bool => $ticket->column($this->reviewBelow) !== Ticket::Review);
        $labeled = $answered->whereNotNull('expected_team');
        $automaticLabeled = $automatic->whereNotNull('expected_team');
        $isCorrect = fn (Ticket $ticket): bool => $ticket->team === $ticket->expected_team;
        $option = TicketTriage::models()[$this->model];

        return [
            'triaged' => $tickets->whereNotNull('triaged_at')->count(),
            'pending' => $tickets->whereNull('triaged_at')->count(),
            'errors' => $tickets->whereNotNull('error')->count(),
            'answered' => $answered->count(),
            'average_ms' => $answered->avg('ms'),
            'cost' => ($answered->sum('input_tokens') * ($option['input_price'] ?? 0.0) + $answered->sum('output_tokens') * ($option['output_price'] ?? 0.0)) / 1_000_000,
            'labeled' => $labeled->count(),
            'correct' => $labeled->filter($isCorrect)->count(),
            'automatic' => $automatic->count(),
            'automatic_labeled' => $automaticLabeled->count(),
            'automatic_correct' => $automaticLabeled->filter($isCorrect)->count(),
        ];
    }
};
?>

<div wire:poll.1s class="flex h-dvh flex-col overflow-hidden">
    @php
        $teams = config('triage.teams');
        $columns = [
            Ticket::Waiting => ['label' => 'Entrada', 'color' => '#ffd37a', 'hint' => 'na fila'],
            ...array_map(fn (array $team): array => ['label' => $team['label'], 'color' => $team['color'], 'hint' => 'choice'], $teams),
            Ticket::Review => ['label' => 'Revisão humana', 'color' => '#ff8280', 'hint' => 'confiança < '.$reviewBelow.'%'],
        ];
        $stats = $this->stats;
        $decimal = fn (?float $value, int $places = 2): string => $value === null ? '—' : number_format($value, $places, ',', '.');
        $percent = fn (?float $value): string => $value === null ? '—' : round($value * 100).'%';
        $ratio = fn (int $part, int $whole): string => $whole > 0 ? $part.' de '.$whole.' ('.number_format($part / $whole * 100, 1, ',', '.').'%)' : '—';
        $level = fn (?float $score, string $question): string => config("triage.{$question}.labels")[(int) round($score ?? 0.0)] ?? '—';
        $inspected = $inspecting ? $this->tickets->firstWhere('id', $inspecting) : null;
    @endphp

    {{-- Top navigation --}}
    <header class="z-10 flex h-[50px] shrink-0 items-center justify-between gap-4 bg-tv-panel px-2.5 shadow-[0_1px_2px_rgba(0,0,0,.9)]">
        <div class="flex items-center gap-5">
            <a href="{{ route('watch') }}" aria-label="Início" class="grid size-8 place-items-center rounded-md bg-tv-purple text-white">
                <x-icon name="play" class="size-4 fill-current" />
            </a>
            <nav class="flex items-center gap-5 text-lg font-semibold">
                <a href="{{ route('watch') }}" class="hover:text-tv-link">Ao vivo</a>
                <a href="{{ route('inbox') }}" aria-current="page" class="text-tv-link">Inbox</a>
                <a href="{{ route('screening') }}" class="hover:text-tv-link">Currículos</a>
            </nav>
        </div>

        <div class="flex items-center gap-1.5" role="group" aria-label="Modelo">
            @foreach (TicketTriage::models() as $key => $option)
                <button type="button" wire:click="selectModel('{{ $key }}')" wire:key="model-{{ $key }}" aria-pressed="{{ $key === $model ? 'true' : 'false' }}" title="{{ $option['provider'].' · '.$option['model'] }}" @class(['h-[30px] rounded px-2.5 font-semibold', 'bg-tv-purple' => $key === $model, 'bg-white/10 hover:bg-white/15' => $key !== $model])>{{ $option['label'] }}</button>
            @endforeach
        </div>
    </header>

    {{-- Board rules and totals --}}
    <section class="flex shrink-0 flex-wrap items-stretch gap-2.5 border-b border-white/10 bg-tv-raised p-2.5">
        <label class="flex min-w-64 flex-1 flex-col justify-between gap-1 rounded bg-black/25 px-3 py-2">
            <span class="flex items-baseline justify-between gap-3">
                <span class="font-mono text-[11px] text-tv-muted uppercase">Confiança mínima para agir sozinho</span>
                <span class="text-lg font-semibold tabular-nums">{{ $reviewBelow }}%</span>
            </span>
            <input type="range" min="0" max="100" step="5" wire:model.live="reviewBelow" class="w-full accent-tv-purple">
            <span class="text-tv-muted">Abaixo disso, o ticket vai para revisão humana.</span>
        </label>

        <label class="flex min-w-64 flex-1 flex-col justify-between gap-1 rounded bg-black/25 px-3 py-2">
            <span class="flex items-baseline justify-between gap-3">
                <span class="font-mono text-[11px] text-tv-muted uppercase">Peso da urgência na prioridade</span>
                <span class="text-lg font-semibold tabular-nums">{{ $urgencyWeight }}% · {{ 100 - $urgencyWeight }}%</span>
            </span>
            <input type="range" min="0" max="100" step="5" wire:model.live="urgencyWeight" class="w-full accent-tv-purple">
            <span class="text-tv-muted">prioridade = urgência × {{ $decimal($urgencyWeight / 100) }} + frustração × {{ $decimal(1 - $urgencyWeight / 100) }}</span>
        </label>

        <dl class="grid flex-2 grid-cols-2 gap-2.5 sm:grid-cols-4">
            @foreach ([
                'Triados' => $stats['triaged'].($stats['pending'] > 0 ? ' · '.$stats['pending'].' na fila' : ''),
                'Tempo médio' => $stats['average_ms'] === null ? '—' : number_format($stats['average_ms'], 0, ',', '.').' ms',
                'Time certo' => $ratio($stats['correct'], $stats['labeled']),
                'Certo no automático' => $ratio($stats['automatic_correct'], $stats['automatic_labeled']),
                'Sem revisão' => $ratio($stats['automatic'], $stats['answered']),
                'Indisponível' => (string) $stats['errors'],
                'Custo total' => $stats['cost'] > 0 ? 'US$ '.number_format($stats['cost'], 6, ',', '.') : '—',
                'Em revisão' => (string) ($stats['answered'] - $stats['automatic'] + $stats['errors']),
            ] as $label => $value)
                <div class="rounded bg-black/25 px-3 py-2">
                    <dt class="truncate font-mono text-[11px] text-tv-muted uppercase">{{ $label }}</dt>
                    <dd class="truncate text-base font-semibold tabular-nums">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    {{-- Board --}}
    <main class="grid min-h-0 flex-1 auto-cols-[minmax(230px,1fr)] grid-flow-col gap-2.5 overflow-x-auto p-2.5">
        @foreach ($columns as $column => $heading)
            @php $tickets = $this->board[$column]; @endphp
            <section wire:key="column-{{ $column }}" class="flex min-h-0 flex-col rounded-md bg-tv-panel" aria-label="{{ $heading['label'] }}">
                <header class="flex shrink-0 items-center justify-between gap-2 border-b border-white/10 px-2.5 py-2">
                    <h2 class="flex min-w-0 items-center gap-2 text-sm font-semibold">
                        <span class="size-2.5 shrink-0 rounded-full" style="background: {{ $heading['color'] }}"></span>
                        <span class="truncate">{{ $heading['label'] }}</span>
                    </h2>
                    <span class="shrink-0 font-mono text-[11px] text-tv-muted">{{ $heading['hint'] }} · <span class="tabular-nums">{{ $tickets->count() }}</span></span>
                </header>

                <div class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto p-2">
                    @foreach ($tickets as $ticket)
                        <button type="button" wire:click="inspect({{ $ticket->id }})" wire:key="ticket-{{ $ticket->id }}" class="flex shrink-0 animate-card-in flex-col gap-1.5 rounded-md bg-tv-raised p-2.5 text-left hover:bg-white/10">
                            <span class="flex items-center justify-between gap-2">
                                <span class="flex min-w-0 items-center gap-1.5">
                                    <span class="truncate font-semibold" style="color: hsl({{ crc32($ticket->customer) % 360 }} 70% 70%)">{{ $ticket->customer }}</span>
                                    @if ($ticket->context !== null)
                                        <span class="shrink-0 rounded bg-[#f5a523]/20 px-1 font-mono text-[10px] text-[#ffd37a]" title="Perfil do cliente no state">+perfil</span>
                                    @endif
                                </span>
                                @if ($ticket->confidence !== null)
                                    <span class="shrink-0 font-mono text-[11px] text-tv-muted tabular-nums" title="Confiança do time">{{ $percent($ticket->confidence) }}</span>
                                @endif
                            </span>
                            <span class="line-clamp-3 text-sm">{{ $ticket->text }}</span>

                            @if ($ticket->error)
                                <span class="self-start rounded bg-[#ffd37a] px-1.5 py-0.5 font-semibold text-tv-bg uppercase">Indisponível</span>
                            @elseif ($ticket->triaged_at)
                                <span class="block h-1.5 w-full shrink-0 overflow-hidden rounded-full bg-white/15" title="Prioridade {{ $decimal($ticket->priority($urgencyWeight)) }}">
                                    <span class="block h-full rounded-full bg-tv-purple transition-all" style="width: {{ round($ticket->priority($urgencyWeight) * 100) }}%"></span>
                                </span>
                                <span class="flex flex-wrap gap-1 font-mono text-[11px]">
                                    <span class="rounded bg-white/10 px-1.5 py-0.5">urgência {{ $decimal($ticket->urgency, 1) }} · {{ $level($ticket->urgency, 'urgency') }}</span>
                                    <span class="rounded bg-white/10 px-1.5 py-0.5">{{ $level($ticket->frustration, 'frustration') }}</span>
                                    @if ($ticket->asksRefund())
                                        <span class="rounded bg-[#f5a523]/25 px-1.5 py-0.5 font-semibold text-[#ffd37a]">pede reembolso</span>
                                    @endif
                                    @if ($column === Ticket::Review && $ticket->team)
                                        <span class="rounded bg-tv-red/25 px-1.5 py-0.5 text-[#ff8280]">palpite: {{ $teams[$ticket->team]['label'] ?? $ticket->team }}</span>
                                    @endif
                                </span>
                            @else
                                <span class="animate-pulse font-mono text-[11px] text-[#ffd37a]">na fila…</span>
                            @endif
                        </button>
                    @endforeach

                    @if ($tickets->isEmpty())
                        <p class="m-auto px-2 text-center text-tv-muted">{{ $column === Ticket::Waiting ? 'Nada na fila.' : 'Vazio.' }}</p>
                    @endif
                </div>

                @if ($column === Ticket::Waiting)
                    <form wire:submit="send" class="flex shrink-0 flex-col gap-2 border-t border-white/10 p-2">
                        <select wire:model="persona" aria-label="Cliente" class="h-[30px] w-full rounded bg-white/10 px-2 ring-tv-purple outline-none focus:ring-2">
                            <option value="">voce · sem perfil no state</option>
                            @foreach (config('triage.personas') as $persona)
                                <option value="{{ $persona }}" wire:key="persona-{{ $persona }}">{{ $persona }} · {{ TicketTriage::profile($persona)['pagamento'] ?? 'não é cliente' }}</option>
                            @endforeach
                        </select>
                        <textarea wire:model="draft" maxlength="1000" rows="2" placeholder="Abrir um ticket" aria-label="Abrir um ticket" class="w-full resize-none rounded bg-white/10 px-2.5 py-2 ring-tv-purple outline-none placeholder:text-tv-muted focus:bg-black focus:ring-2"></textarea>
                        @error('draft')
                            <p class="text-[#ff8280]">{{ $message }}</p>
                        @enderror
                        <button type="submit" wire:loading.attr="disabled" wire:target="send" class="h-[30px] self-end rounded bg-tv-purple px-2.5 font-semibold hover:bg-tv-purple-hover disabled:opacity-50">Enviar</button>
                    </form>
                @endif
            </section>
        @endforeach
    </main>

    {{-- Ticket answers --}}
    @if ($inspected)
        @php
            $bars = fn (?array $probabilities, array $labels): array => collect($labels)->map(fn (string $label, int|string $key): array => ['label' => $label, 'value' => (float) ($probabilities[$key] ?? 0.0)])->all();
            $priority = $inspected->priority($urgencyWeight);
            $sections = [
                ['type' => 'choice', 'title' => 'Qual time resolve?', 'answer' => $inspected->team ? ($teams[$inspected->team]['label'] ?? $inspected->team) : '—', 'bars' => $bars($inspected->team_probabilities, array_map(fn (array $team): string => $team['label'], $teams)), 'top' => $inspected->team ? ($teams[$inspected->team]['label'] ?? null) : null],
                ['type' => 'score', 'title' => 'Quão urgente?', 'answer' => $decimal($inspected->urgency).' · '.$level($inspected->urgency, 'urgency'), 'bars' => $bars($inspected->urgency_probabilities, config('triage.urgency.labels')), 'top' => $level($inspected->urgency, 'urgency')],
                ['type' => 'score', 'title' => 'Quão frustrado?', 'answer' => $decimal($inspected->frustration).' · '.$level($inspected->frustration, 'frustration'), 'bars' => $bars($inspected->frustration_probabilities, config('triage.frustration.labels')), 'top' => $level($inspected->frustration, 'frustration')],
                ['type' => 'noul', 'title' => 'Pede reembolso?', 'answer' => $decimal($inspected->refund), 'bars' => [['label' => 'sim', 'value' => (float) ($inspected->refund ?? 0.0)]], 'top' => $inspected->asksRefund() ? 'sim' : null],
            ];
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 p-4" x-data x-on:keydown.escape.window="$wire.set('inspecting', null)" role="dialog" aria-modal="true" aria-label="Respostas do ticket">
            <div class="flex max-h-full w-full max-w-4xl flex-col rounded-md bg-tv-panel shadow-2xl">
                <header class="flex shrink-0 items-center justify-between gap-4 border-b border-white/10 px-5 py-3">
                    <h2 class="truncate text-lg font-semibold">Ticket de {{ $inspected->customer }}</h2>
                    <button type="button" wire:click="$set('inspecting', null)" class="shrink-0 rounded bg-white/10 px-2.5 py-1 font-semibold hover:bg-white/15">Fechar</button>
                </header>

                <div class="flex min-h-0 flex-col gap-5 overflow-y-auto p-5">
                    <p class="text-base">“{{ $inspected->text }}”</p>

                    @if ($inspected->context !== null)
                        <section>
                            <h3 class="flex items-baseline justify-between gap-3">
                                <span class="font-semibold">State enviado ao modelo</span>
                                <span class="font-mono text-xs text-[#ffd37a]">ticket + perfil do cliente</span>
                            </h3>
                            <pre class="mt-2 max-h-56 overflow-auto rounded bg-black/40 p-3 font-mono text-xs whitespace-pre-wrap text-tv-muted">{{ json_encode(TicketTriage::state($inspected), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </section>
                    @endif

                    @if ($inspected->error)
                        <p class="text-[#ffd37a]">O modelo falhou e o ticket ficou para uma pessoa: {{ $inspected->error }}</p>
                    @elseif (! $inspected->triaged_at)
                        <p class="animate-pulse text-[#ffd37a]">Na fila, esperando a resposta do modelo…</p>
                    @else
                        <div class="grid gap-5 sm:grid-cols-2">
                            @foreach ($sections as $section)
                                <section wire:key="answer-{{ $loop->index }}">
                                    <h3 class="flex items-baseline justify-between gap-3">
                                        <span><span class="font-mono text-xs text-tv-link">{{ $section['type'] }}</span> <span class="font-semibold">{{ $section['title'] }}</span></span>
                                        <span class="shrink-0 text-base font-semibold tabular-nums">{{ $section['answer'] }}</span>
                                    </h3>
                                    <ul class="mt-2 flex flex-col gap-1.5">
                                        @foreach ($section['bars'] as $bar)
                                            <li class="grid grid-cols-[7rem_1fr_3rem] items-center gap-2.5 text-sm">
                                                <span @class(['truncate', 'font-semibold' => $bar['label'] === $section['top'], 'text-tv-muted' => $bar['label'] !== $section['top']])>{{ $bar['label'] }}</span>
                                                <span class="h-2 overflow-hidden rounded-full bg-white/10">
                                                    <span @class(['block h-full rounded-full', 'bg-tv-purple' => $bar['label'] === $section['top'], 'bg-white/30' => $bar['label'] !== $section['top']]) style="width: {{ round($bar['value'] * 100) }}%"></span>
                                                </span>
                                                <span class="text-right font-mono text-xs tabular-nums">{{ $decimal($bar['value']) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>
                            @endforeach
                        </div>

                        <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            @foreach ([
                                'Confiança do time' => $decimal($inspected->confidence).($inspected->column($reviewBelow) === Ticket::Review ? ' · revisão' : ' · automático'),
                                'Prioridade' => $decimal($priority),
                                'Gabarito' => $inspected->expected_team ? ($teams[$inspected->expected_team]['label'] ?? $inspected->expected_team).($inspected->team === $inspected->expected_team ? ' · bateu' : ' · não bateu') : '—',
                                'Tempo · tokens' => number_format((int) $inspected->ms, 0, ',', '.').' ms · '.$inspected->input_tokens.' → '.$inspected->output_tokens,
                            ] as $label => $value)
                                <div class="rounded bg-black/25 px-3 py-2">
                                    <dt class="truncate font-mono text-[11px] text-tv-muted uppercase">{{ $label }}</dt>
                                    <dd class="truncate text-base font-semibold tabular-nums">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>

                        <p class="font-mono text-xs text-tv-muted">prioridade = {{ $decimal($urgencyWeight / 100) }} × {{ $decimal(TicketTriage::normalize($inspected->urgency, 'urgency')) }} + {{ $decimal(1 - $urgencyWeight / 100) }} × {{ $decimal(TicketTriage::normalize($inspected->frustration, 'frustration')) }} = {{ $decimal($priority) }} · calculada no código, sem nova chamada</p>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
