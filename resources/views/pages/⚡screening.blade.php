<?php

use App\Ai\Agents\ResumeCoach;
use App\Ai\Agents\ResumeReviewer;
use App\Ai\Agents\RubricWriter;
use App\Ai\Agents\TailoredResumeWriter;
use App\Ai\ResumeScreening;
use App\Ai\ResumeText;
use App\Ai\Tools\MedirAderencia;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolResult;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('beerandcode - Currículos')] class extends Component
{
    use WithFileUploads;

    /**
     * The key of the selected decision model in config/moderation.php.
     */
    public string $model = '';

    /**
     * The weighted score, from 0 to 100, from which a resume is invited to an interview.
     */
    public int $inviteAbove = 0;

    /**
     * The points, from 0 to 100, a knockout criterion needs to pass.
     */
    public int $knockoutBelow = 0;

    public string $job = '';

    public string $title = '';

    /**
     * The criteria the LLM wrote from the job description.
     *
     * @var list<array{key: string, type: string, question: string, weight: int, knockout: bool, options: list<array{label: string, description: string, points: int}>}>
     */
    public array $criteria = [];

    public ?int $rubricMs = null;

    public ?string $rubricError = null;

    /**
     * The uploaded resume, read into $resumeText and dropped right after.
     */
    public ?TemporaryUploadedFile $upload = null;

    public string $fileName = '';

    public string $resumeText = '';

    /**
     * The decision model's answer for each criterion, keyed by criterion.
     *
     * @var array<string, array{points: float, answer: string, probability: float, probabilities: array<string, float>}>
     */
    public array $results = [];

    public ?int $decisionMs = null;

    public ?string $decisionError = null;

    public int $inputTokens = 0;

    /**
     * The review the LLM wrote after the decision, streamed while it is written.
     */
    public string $review = '';

    public ?int $reviewMs = null;

    public ?string $reviewError = null;

    /**
     * Whether the tailoring panel is open.
     */
    public bool $tailoring = false;

    /**
     * The coach's interview so far. The opening prompt is hidden from the page.
     *
     * @var list<array{role: string, content: string, hidden?: bool}>
     */
    public array $interview = [];

    public string $answer = '';

    public ?string $coachError = null;

    /**
     * The tailored resume in Markdown, streamed while the LLM writes it.
     */
    public string $tailored = '';

    /**
     * The decision model's score of each draft the LLM measured, in order.
     *
     * @var list<array{score: float, ms: int}>
     */
    public array $measurements = [];

    /**
     * The decision model's answers for the final tailored resume, measured in code.
     *
     * @var array<string, array{points: float, answer: string, probability: float, probabilities: array<string, float>}>
     */
    public array $tailoredResults = [];

    public ?int $tailoredMs = null;

    public ?string $tailoredError = null;

    public function mount(): void
    {
        $this->model = ResumeScreening::defaultModel();
        $this->inviteAbove = (int) config('screening.invite_above');
        $this->knockoutBelow = (int) config('screening.knockout_below');
    }

    /**
     * Keeps the rules inside the range of their sliders and reads an uploaded resume into text.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['inviteAbove', 'knockoutBelow'], true)) {
            $this->{$property} = max(0, min(100, $this->{$property}));
        }

        if ($property === 'upload') {
            $this->readUpload();
        }
    }

    public function selectModel(string $model): void
    {
        abort_unless(array_key_exists($model, ResumeScreening::models()), 404);

        $this->model = $model;
        $this->resetDecision();
    }

    /**
     * Asks the LLM for the criteria of the job. This is the slow, expensive call, made once per job.
     */
    public function writeRubric(): void
    {
        $this->job = trim($this->job);
        $this->validate(['job' => 'required|string|max:8000'], attributes: ['job' => 'vaga']);

        $this->reset('criteria', 'title', 'rubricMs', 'rubricError');
        $this->resetDecision();
        $startedAt = microtime(true);

        try {
            $response = (new RubricWriter)->prompt($this->job, provider: config('screening.llm.provider'), model: config('screening.llm.model'));

            $this->title = (string) ($response['title'] ?? '');
            $this->criteria = ResumeScreening::normalize((array) ($response['criteria'] ?? []));

            if ($this->criteria === []) {
                $this->rubricError = 'O LLM não devolveu nenhum critério válido.';
            }
        } catch (Throwable $exception) {
            report($exception);

            $this->rubricError = $exception->getMessage();
        }

        $this->rubricMs = (int) round((microtime(true) - $startedAt) * 1000);
    }

    public function removeCriterion(string $key): void
    {
        $this->criteria = array_values(array_filter($this->criteria, fn (array $criterion): bool => $criterion['key'] !== $key));
        $this->resetDecision();
    }

    /**
     * Asks the decision model every criterion in a single call and stores the points. The verdict is
     * shown as soon as this returns; the LLM review is requested right after, in a second request.
     */
    public function evaluate(): void
    {
        $this->resumeText = trim($this->resumeText);
        $this->validate([
            'criteria' => 'required|array|min:1',
            'resumeText' => 'required|string',
        ], ['criteria.required' => 'Gere os critérios da vaga primeiro.'], ['resumeText' => 'currículo']);

        $this->resetDecision();
        $startedAt = microtime(true);

        try {
            ['results' => $this->results, 'input_tokens' => $this->inputTokens] = ResumeScreening::measure($this->criteria, $this->resumeText, $this->model);
        } catch (Throwable $exception) {
            report($exception);

            $this->results = [];
            $this->decisionError = $exception->getMessage();
        }

        $this->decisionMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($this->results !== []) {
            $this->js('$wire.writeReview()');
        }
    }

    /**
     * Streams the LLM's review of the resume, told the decision so it explains it instead of redoing it.
     */
    public function writeReview(): void
    {
        if ($this->results === []) {
            return;
        }

        $this->reset('review', 'reviewMs', 'reviewError');
        $startedAt = microtime(true);

        try {
            $stream = (new ResumeReviewer($this->job, $this->decisionSummary()))
                ->stream($this->excerpt(), provider: config('screening.llm.provider'), model: config('screening.llm.model'));

            foreach ($stream as $event) {
                if ($event instanceof TextDelta) {
                    $this->review .= $event->delta;
                    $this->stream(to: 'review', content: $event->delta);
                }
            }
        } catch (Throwable $exception) {
            report($exception);

            $this->reviewError = $exception->getMessage();
        }

        $this->reviewMs = (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Opens the tailoring panel and starts the interview from the criteria the resume scored low on.
     */
    public function startTailoring(): void
    {
        if ($this->results === []) {
            return;
        }

        $this->tailoring = true;

        if ($this->interview === []) {
            $this->interview[] = ['role' => 'user', 'content' => 'Comece a entrevista.', 'hidden' => true];
            $this->js('$wire.askCoach()');
        }
    }

    /**
     * Shows the candidate's answer right away; the coach's next question comes in a second request.
     */
    public function reply(): void
    {
        $this->answer = trim($this->answer);
        $this->validate(['answer' => 'required|string|max:2000'], attributes: ['answer' => 'resposta']);

        $this->interview[] = ['role' => 'user', 'content' => $this->answer];
        $this->reset('answer');
        $this->js('$wire.askCoach()');
    }

    /**
     * Asks the LLM coach for the next question, given the interview so far.
     */
    public function askCoach(): void
    {
        $last = end($this->interview);

        if ($last === false || $last['role'] !== 'user') {
            return;
        }

        $this->coachError = null;

        try {
            $response = (new ResumeCoach($this->job, $this->excerpt(), $this->gapsSummary(), array_slice($this->interview, 0, -1)))
                ->prompt($last['content'], provider: config('screening.llm.provider'), model: config('screening.llm.model'));

            $this->interview[] = ['role' => 'assistant', 'content' => trim($response->text)];
        } catch (Throwable $exception) {
            report($exception);

            $this->coachError = $exception->getMessage();
        }
    }

    /**
     * Streams the tailored resume while the LLM writes it. The LLM measures each draft with the
     * decision model through MedirAderencia and revises; each measurement shows up as it happens.
     * The final resume is measured once more in code, so the score shown never comes from the LLM.
     */
    public function writeTailored(): void
    {
        if ($this->results === [] || $this->candidateAnswers() === []) {
            return;
        }

        $this->reset('tailored', 'measurements', 'tailoredResults', 'tailoredMs', 'tailoredError');
        $startedAt = microtime(true);

        $measure = new MedirAderencia($this->criteria, $this->model, function (float $score, array $results, int $ms): void {
            $this->measurements[] = ['score' => $score, 'ms' => $ms];
            $this->stream(to: 'measurements', content: sprintf("rascunho %d · %d/100 · %s ms\n", count($this->measurements), round($score), number_format($ms, 0, ',', '.')));
        });

        try {
            $stream = (new TailoredResumeWriter($this->job, $this->interviewTranscript(), $measure))
                ->stream($this->excerpt(), provider: config('screening.llm.provider'), model: config('screening.llm.model'));

            foreach ($stream as $event) {
                if ($event instanceof ToolResult) {
                    $this->tailored = '';
                    $this->stream(to: 'tailored', content: '', replace: true);
                }

                if ($event instanceof TextDelta) {
                    $this->tailored .= $event->delta;
                    $this->stream(to: 'tailored', content: $event->delta);
                }
            }

            $this->tailored = trim($this->tailored);

            if ($this->tailored !== '') {
                ['results' => $this->tailoredResults] = ResumeScreening::measure($this->criteria, $this->tailored, $this->model);
            }
        } catch (Throwable $exception) {
            report($exception);

            $this->tailoredError = $exception->getMessage();
        }

        $this->tailoredMs = (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Downloads the tailored resume as a PDF.
     */
    public function downloadPdf(): ?StreamedResponse
    {
        if ($this->tailored === '') {
            return null;
        }

        $pdf = Pdf::loadView('pdf.resume', [
            'title' => $this->title ?: 'Currículo',
            'html' => Str::markdown($this->tailored, ['html_input' => 'escape', 'allow_unsafe_links' => false]),
        ])->output();

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf;
        }, 'curriculo-'.(Str::slug($this->title) ?: 'aderente').'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /**
     * The verdict over the stored answers, recalculated when a rule changes, without a new call.
     *
     * @return array{invite: bool, score: float, failed: list<string>, strengths: list<string>, weaknesses: list<string>}
     */
    #[Computed]
    public function verdict(): array
    {
        return ResumeScreening::verdict($this->criteria, $this->results, $this->inviteAbove, $this->knockoutBelow, (int) config('screening.strength_above'));
    }

    /**
     * The verdict of the tailored resume under the same rules, or null before it is measured.
     *
     * @return array{invite: bool, score: float, failed: list<string>, strengths: list<string>, weaknesses: list<string>}|null
     */
    #[Computed]
    public function tailoredVerdict(): ?array
    {
        return $this->tailoredResults === []
            ? null
            : ResumeScreening::verdict($this->criteria, $this->tailoredResults, $this->inviteAbove, $this->knockoutBelow, (int) config('screening.strength_above'));
    }

    /**
     * Reads the uploaded file into text and drops the file.
     */
    private function readUpload(): void
    {
        $this->validate(['upload' => 'required|file|extensions:'.implode(',', ResumeText::Extensions).'|max:'.config('screening.max_upload')], attributes: ['upload' => 'currículo']);

        try {
            $this->resumeText = ResumeText::from($this->upload->getRealPath(), $this->upload->getClientOriginalExtension());
            $this->fileName = $this->upload->getClientOriginalName();
        } catch (Throwable $exception) {
            report($exception);

            $this->addError('upload', $exception->getMessage());
        }

        $this->upload->delete();
        $this->upload = null;
        $this->resetDecision();
    }

    /**
     * The resume text sent to the models, cut at the configured length.
     */
    private function excerpt(): string
    {
        return mb_substr($this->resumeText, 0, (int) config('screening.max_characters'));
    }

    /**
     * The criteria, their answers and the verdict as plain text for the reviewer.
     */
    private function decisionSummary(): string
    {
        $verdict = $this->verdict;
        $lines = [];

        foreach ($this->criteria as $criterion) {
            $result = $this->results[$criterion['key']];
            $lines[] = sprintf('- %s (%s, peso %d%s): %s, %d de 100 pontos', $criterion['question'], $criterion['type'], $criterion['weight'], $criterion['knockout'] ? ', eliminatório' : '', $result['answer'], round($result['points']));
        }

        $lines[] = sprintf('Nota ponderada: %d de 100. Veredito: %s.', round($verdict['score']), $verdict['invite'] ? 'chamar para entrevista' : 'não chamar');

        return implode("\n", $lines);
    }

    /**
     * The criteria the resume scored low on, heaviest first, as plain text for the coach.
     */
    private function gapsSummary(): string
    {
        $criteria = $this->criteria;
        usort($criteria, fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
        $lines = [];

        foreach ($criteria as $criterion) {
            $result = $this->results[$criterion['key']] ?? null;

            if ($result !== null && $result['points'] < (int) config('screening.strength_above')) {
                $lines[] = sprintf('- %s (peso %d%s): %s, %d de 100 pontos', $criterion['question'], $criterion['weight'], $criterion['knockout'] ? ', eliminatório' : '', $result['answer'], round($result['points']));
            }
        }

        return $lines === [] ? 'Nenhum: o currículo já vai bem em todos os critérios.' : implode("\n", $lines);
    }

    /**
     * The candidate's answers in the interview.
     *
     * @return list<string>
     */
    private function candidateAnswers(): array
    {
        return array_values(array_column(array_filter($this->interview, fn (array $message): bool => $message['role'] === 'user' && ! ($message['hidden'] ?? false)), 'content'));
    }

    /**
     * The visible interview as plain text for the writer.
     */
    private function interviewTranscript(): string
    {
        $lines = [];

        foreach ($this->interview as $message) {
            if (! ($message['hidden'] ?? false)) {
                $lines[] = ($message['role'] === 'user' ? 'Candidato: ' : 'Orientador: ').$message['content'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Forgets the decision and everything built on it.
     */
    private function resetDecision(): void
    {
        $this->reset('results', 'decisionMs', 'decisionError', 'inputTokens', 'review', 'reviewMs', 'reviewError', 'tailoring', 'interview', 'answer', 'coachError', 'tailored', 'measurements', 'tailoredResults', 'tailoredMs', 'tailoredError');
    }
};
?>

<div class="flex h-dvh flex-col overflow-hidden">
    @php
        $criteriaByKey = array_column($criteria, null, 'key');
        $verdict = $this->verdict;
        $decided = $results !== [];
        $milliseconds = fn (?int $ms): string => $ms === null ? '—' : number_format($ms, 0, ',', '.').' ms';
        $option = ResumeScreening::models()[$model];
        $typeColors = ['noul' => 'bg-[#f5a523]/20 text-[#ffd37a]', 'choice' => 'bg-[#38bdf8]/20 text-[#7dd3fc]', 'score' => 'bg-tv-purple/25 text-tv-link'];
    @endphp

    {{-- Top navigation --}}
    <header class="z-10 flex h-[50px] shrink-0 items-center justify-between gap-4 bg-tv-panel px-2.5 shadow-[0_1px_2px_rgba(0,0,0,.9)]">
        <div class="flex items-center gap-5">
            <a href="{{ route('watch') }}" aria-label="Início" class="grid size-8 place-items-center rounded-md bg-tv-purple text-white">
                <x-icon name="play" class="size-4 fill-current" />
            </a>
            <nav class="flex items-center gap-5 text-lg font-semibold">
                <a href="{{ route('watch') }}" class="hover:text-tv-link">Ao vivo</a>
                <a href="{{ route('inbox') }}" class="hover:text-tv-link">Inbox</a>
                <a href="{{ route('screening') }}" aria-current="page" class="text-tv-link">Currículos</a>
            </nav>
        </div>

        <div class="flex items-center gap-1.5" role="group" aria-label="Modelo de decisão">
            @foreach (ResumeScreening::models() as $key => $choice)
                <button type="button" wire:click="selectModel('{{ $key }}')" wire:key="model-{{ $key }}" aria-pressed="{{ $key === $model ? 'true' : 'false' }}" title="{{ $choice['provider'].' · '.$choice['model'] }}" @class(['h-[30px] rounded px-2.5 font-semibold', 'bg-tv-purple' => $key === $model, 'bg-white/10 hover:bg-white/15' => $key !== $model])>{{ $choice['label'] }}</button>
            @endforeach
        </div>
    </header>

    <main class="grid min-h-0 flex-1 gap-2.5 overflow-y-auto p-2.5 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_minmax(0,1.1fr)] lg:overflow-hidden">
        {{-- 1. Job and criteria --}}
        <section class="flex min-h-0 flex-col rounded-md bg-tv-panel" aria-label="Vaga">
            <header class="flex shrink-0 items-center justify-between gap-2 border-b border-white/10 px-3 py-2">
                <h2 class="text-sm font-semibold">1 · Vaga e critérios</h2>
                <span class="rounded bg-[#34d399]/20 px-1.5 py-0.5 font-mono text-[11px] text-[#6ee7b7]">LLM · {{ config('screening.llm.label') }}</span>
            </header>

            <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-3">
                <form wire:submit="writeRubric" class="flex flex-col gap-2">
                    <textarea wire:model="job" rows="6" maxlength="8000" placeholder="Cole a descrição da vaga" aria-label="Descrição da vaga" class="w-full resize-y rounded bg-white/10 px-2.5 py-2 ring-tv-purple outline-none placeholder:text-tv-muted focus:bg-black focus:ring-2"></textarea>
                    @error('job')
                        <p class="text-[#ff8280]">{{ $message }}</p>
                    @enderror
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-mono text-[11px] text-tv-muted">
                            <span wire:loading wire:target="writeRubric" class="animate-pulse text-[#ffd37a]">LLM escrevendo critérios…</span>
                            <span wire:loading.remove wire:target="writeRubric">{{ $rubricMs !== null ? 'gerado em '.$milliseconds($rubricMs) : '' }}</span>
                        </span>
                        <button type="submit" wire:loading.attr="disabled" wire:target="writeRubric" class="h-[30px] rounded bg-tv-purple px-2.5 font-semibold hover:bg-tv-purple-hover disabled:opacity-50">Gerar critérios</button>
                    </div>
                </form>

                @if ($rubricError)
                    <p class="text-[#ffd37a]">O LLM falhou: {{ $rubricError }}</p>
                @endif

                @if ($title)
                    <h3 class="text-base font-semibold">{{ $title }}</h3>
                @endif

                @foreach ($criteria as $criterion)
                    <article wire:key="criterion-{{ $criterion['key'] }}" class="flex shrink-0 animate-card-in flex-col gap-2 rounded-md bg-tv-raised p-2.5">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-semibold">{{ $criterion['question'] }}</p>
                            <button type="button" wire:click="removeCriterion('{{ $criterion['key'] }}')" aria-label="Remover critério" class="shrink-0 rounded px-1.5 text-tv-muted hover:bg-white/10 hover:text-tv-text">×</button>
                        </div>
                        <div class="flex flex-wrap gap-1 font-mono text-[11px]">
                            <span class="rounded px-1.5 py-0.5 {{ $typeColors[$criterion['type']] }}">{{ $criterion['type'] }}</span>
                            <span class="rounded bg-white/10 px-1.5 py-0.5">peso {{ $criterion['weight'] }}</span>
                            @if ($criterion['knockout'])
                                <span class="rounded bg-tv-red/25 px-1.5 py-0.5 text-[#ff8280]">eliminatório</span>
                            @endif
                        </div>
                        <ul class="flex flex-col gap-0.5 text-sm">
                            @foreach ($criterion['options'] as $choice)
                                <li wire:key="option-{{ $criterion['key'] }}-{{ $loop->index }}" class="grid grid-cols-[1fr_auto] gap-2" title="{{ $choice['description'] }}">
                                    <span class="truncate text-tv-muted">{{ $choice['label'] }}</span>
                                    <span class="font-mono text-xs tabular-nums">{{ $choice['points'] }} pts</span>
                                </li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>
        </section>

        {{-- 2. Resume --}}
        <section class="flex min-h-0 flex-col rounded-md bg-tv-panel" aria-label="Currículo">
            <header class="flex shrink-0 items-center justify-between gap-2 border-b border-white/10 px-3 py-2">
                <h2 class="text-sm font-semibold">2 · Currículo</h2>
                <span class="font-mono text-[11px] text-tv-muted">{{ implode(' · ', ResumeText::Extensions) }}</span>
            </header>

            <form wire:submit="evaluate" class="flex min-h-0 flex-1 flex-col gap-2 p-3">
                <label class="flex shrink-0 cursor-pointer flex-col items-center gap-1 rounded-md border border-dashed border-white/20 px-3 py-4 text-center hover:bg-white/5">
                    <span class="font-semibold">{{ $fileName ?: 'Escolher arquivo' }}</span>
                    <span class="text-tv-muted">
                        <span wire:loading wire:target="upload" class="animate-pulse text-[#ffd37a]">Extraindo texto…</span>
                        <span wire:loading.remove wire:target="upload">PDF, Word ou texto, até {{ intdiv(config('screening.max_upload'), 1024) }} MB</span>
                    </span>
                    <input type="file" wire:model="upload" accept="{{ implode(',', array_map(fn (string $extension): string => '.'.$extension, ResumeText::Extensions)) }}" class="sr-only">
                </label>
                @error('upload')
                    <p class="text-[#ff8280]">{{ $message }}</p>
                @enderror

                <textarea wire:model="resumeText" placeholder="O texto extraído aparece aqui. Também dá para colar." aria-label="Texto do currículo" class="min-h-48 w-full flex-1 resize-none rounded bg-white/10 px-2.5 py-2 font-mono text-xs ring-tv-purple outline-none placeholder:text-tv-muted focus:bg-black focus:ring-2"></textarea>
                @error('resumeText')
                    <p class="text-[#ff8280]">{{ $message }}</p>
                @enderror
                @error('criteria')
                    <p class="text-[#ff8280]">{{ $message }}</p>
                @enderror

                <div class="flex shrink-0 items-center justify-between gap-2">
                    <span class="font-mono text-[11px] text-tv-muted tabular-nums">{{ number_format(mb_strlen($resumeText), 0, ',', '.') }} caracteres</span>
                    <button type="submit" wire:loading.attr="disabled" wire:target="evaluate,upload" class="h-[30px] rounded bg-tv-purple px-2.5 font-semibold hover:bg-tv-purple-hover disabled:opacity-50">Avaliar</button>
                </div>
            </form>
        </section>

        {{-- 3. Decision and review --}}
        <section class="flex min-h-0 flex-col rounded-md bg-tv-panel" aria-label="Resultado">
            <header class="flex shrink-0 items-center justify-between gap-2 border-b border-white/10 px-3 py-2">
                <h2 class="text-sm font-semibold">3 · Resultado</h2>
                <span class="rounded bg-tv-purple/25 px-1.5 py-0.5 font-mono text-[11px] text-tv-link">decision · {{ $option['label'] }}</span>
            </header>

            <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-3">
                <div class="grid shrink-0 gap-2 sm:grid-cols-2">
                    <label class="flex flex-col gap-1 rounded bg-black/25 px-3 py-2">
                        <span class="flex items-baseline justify-between gap-3">
                            <span class="font-mono text-[11px] text-tv-muted uppercase">Nota mínima</span>
                            <span class="text-base font-semibold tabular-nums">{{ $inviteAbove }}</span>
                        </span>
                        <input type="range" min="0" max="100" step="5" wire:model.live="inviteAbove" class="w-full accent-tv-purple">
                    </label>
                    <label class="flex flex-col gap-1 rounded bg-black/25 px-3 py-2">
                        <span class="flex items-baseline justify-between gap-3">
                            <span class="font-mono text-[11px] text-tv-muted uppercase">Corte eliminatório</span>
                            <span class="text-base font-semibold tabular-nums">{{ $knockoutBelow }}</span>
                        </span>
                        <input type="range" min="0" max="100" step="5" wire:model.live="knockoutBelow" class="w-full accent-tv-purple">
                    </label>
                </div>

                <p wire:loading wire:target="evaluate" class="animate-pulse text-[#ffd37a]">Modelo de decisão respondendo {{ count($criteria) }} critérios numa chamada…</p>

                @if ($decisionError)
                    <p class="text-[#ffd37a]">O modelo de decisão falhou: {{ $decisionError }}</p>
                @endif

                @if ($decided)
                    <div wire:loading.remove wire:target="evaluate" class="flex flex-col gap-3">
                        {{-- Decision: instant, plain code over the answers --}}
                        <div @class(['flex shrink-0 animate-card-in items-center justify-between gap-3 rounded-md px-4 py-3', 'bg-[#34d399]/15 ring-1 ring-[#34d399]/50' => $verdict['invite'], 'bg-tv-red/15 ring-1 ring-tv-red/50' => ! $verdict['invite']])>
                            <div>
                                <p @class(['text-xl font-bold', 'text-[#6ee7b7]' => $verdict['invite'], 'text-[#ff8280]' => ! $verdict['invite']])>{{ $verdict['invite'] ? 'Chamar para entrevista' : 'Não chamar' }}</p>
                                <p class="font-mono text-[11px] text-tv-muted">
                                    decision em {{ $milliseconds($decisionMs) }} · {{ $inputTokens }} tokens · veredito no código
                                    @if ($verdict['failed'] !== [])
                                        · reprovou eliminatório
                                    @endif
                                </p>
                            </div>
                            <p class="text-3xl font-bold tabular-nums">{{ round($verdict['score']) }}<span class="text-base text-tv-muted">/100</span></p>
                        </div>

                        <button type="button" wire:click="startTailoring" class="flex h-[34px] shrink-0 items-center justify-center gap-2 rounded bg-tv-purple px-3 font-semibold hover:bg-tv-purple-hover">
                            <x-icon name="star" class="size-4" />
                            Gerar CV aderente
                        </button>

                        <div class="grid shrink-0 gap-2 sm:grid-cols-2">
                            @foreach (['strengths' => ['Pontos fortes', 'text-[#6ee7b7]'], 'weaknesses' => ['Pontos fracos', 'text-[#ff8280]']] as $group => [$heading, $color])
                                <div class="rounded bg-black/25 px-3 py-2">
                                    <p class="flex items-baseline justify-between gap-2">
                                        <span class="font-semibold {{ $color }}">{{ $heading }}</span>
                                        <span class="font-mono text-[11px] text-tv-muted">decision</span>
                                    </p>
                                    <ul class="mt-1 flex flex-col gap-0.5 text-sm">
                                        @forelse ($verdict[$group] as $key)
                                            <li wire:key="{{ $group }}-{{ $key }}">{{ $criteriaByKey[$key]['question'] }} <span class="text-tv-muted">· {{ $results[$key]['answer'] }}</span></li>
                                        @empty
                                            <li class="text-tv-muted">Nenhum.</li>
                                        @endforelse
                                    </ul>
                                </div>
                            @endforeach
                        </div>

                        <ul class="flex shrink-0 flex-col gap-2">
                            @foreach ($criteria as $criterion)
                                @php
                                    $result = $results[$criterion['key']];
                                    $failed = in_array($criterion['key'], $verdict['failed'], true);
                                @endphp
                                <li wire:key="result-{{ $criterion['key'] }}" class="rounded bg-tv-raised px-3 py-2">
                                    <p class="flex items-baseline justify-between gap-3 text-sm">
                                        <span class="min-w-0">
                                            <span class="font-mono text-xs {{ $typeColors[$criterion['type']] }} rounded px-1">{{ $criterion['type'] }}</span>
                                            {{ $criterion['question'] }}
                                        </span>
                                        <span @class(['shrink-0 font-semibold tabular-nums', 'text-[#ff8280]' => $failed])>{{ round($result['points']) }} pts</span>
                                    </p>
                                    <div class="mt-1.5 flex h-2 overflow-hidden rounded-full bg-white/10" title="{{ $result['answer'] }} · {{ round($result['probability'] * 100) }}%">
                                        <span @class(['block h-full rounded-full transition-all', 'bg-tv-red' => $failed, 'bg-tv-purple' => ! $failed]) style="width: {{ round($result['points']) }}%"></span>
                                    </div>
                                    <p class="mt-1 font-mono text-[11px] text-tv-muted">
                                        {{ $result['answer'] }} · {{ round($result['probability'] * 100) }}% · peso {{ $criterion['weight'] }}{{ $criterion['knockout'] ? ' · eliminatório' : '' }}
                                    </p>
                                </li>
                            @endforeach
                        </ul>

                        {{-- Review: slow, written by the LLM after the decision --}}
                        <div class="flex flex-col gap-1.5 rounded-md bg-black/25 px-3 py-2">
                            <p class="flex items-baseline justify-between gap-2">
                                <span class="font-semibold">Análise escrita</span>
                                <span class="rounded bg-[#34d399]/20 px-1.5 py-0.5 font-mono text-[11px] text-[#6ee7b7]">
                                    LLM · {{ config('screening.llm.label') }}{{ $reviewMs !== null ? ' · '.$milliseconds($reviewMs) : '' }}
                                </span>
                            </p>
                            @if ($reviewError)
                                <p class="text-[#ffd37a]">O LLM falhou: {{ $reviewError }}</p>
                            @endif
                            <p wire:loading wire:target="writeReview" class="animate-pulse font-mono text-[11px] text-[#ffd37a]">LLM escrevendo…</p>
                            <p wire:stream="review" class="text-sm whitespace-pre-line">{{ $review }}</p>
                        </div>
                    </div>
                @elseif (! $decisionError)
                    <p wire:loading.remove wire:target="evaluate" class="m-auto px-4 text-center text-tv-muted">Gere os critérios, suba um currículo e clique em Avaliar.</p>
                @endif
            </div>
        </section>
    </main>

    {{-- Tailored resume: the LLM interviews, writes and measures each draft with the decision model --}}
    @if ($tailoring)
        @php
            $tailoredVerdict = $this->tailoredVerdict;
            $answered = collect($interview)->where('role', 'user')->where('hidden', null)->isNotEmpty();
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 p-4" x-data x-on:keydown.escape.window="$wire.set('tailoring', false)" role="dialog" aria-modal="true" aria-label="Currículo aderente">
            <div class="flex h-full max-h-[52rem] w-full max-w-6xl flex-col rounded-md bg-tv-panel shadow-2xl">
                <header class="flex shrink-0 items-center justify-between gap-4 border-b border-white/10 px-5 py-3">
                    <h2 class="truncate text-lg font-semibold">Currículo aderente{{ $title ? ' · '.$title : '' }}</h2>
                    <button type="button" wire:click="$set('tailoring', false)" class="shrink-0 rounded bg-white/10 px-2.5 py-1 font-semibold hover:bg-white/15">Fechar</button>
                </header>

                <div class="grid min-h-0 flex-1 gap-2.5 overflow-y-auto p-2.5 lg:grid-cols-2 lg:overflow-hidden">
                    {{-- Interview --}}
                    <section class="flex min-h-0 flex-col rounded-md bg-tv-raised" aria-label="Entrevista">
                        <header class="flex shrink-0 items-center justify-between gap-2 border-b border-white/10 px-3 py-2">
                            <h3 class="text-sm font-semibold">Entrevista</h3>
                            <span class="rounded bg-[#34d399]/20 px-1.5 py-0.5 font-mono text-[11px] text-[#6ee7b7]">LLM · orientador</span>
                        </header>

                        <ol class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto p-3">
                            @foreach ($interview as $message)
                                @continue($message['hidden'] ?? false)
                                <li wire:key="message-{{ $loop->index }}" @class(['max-w-[85%] animate-card-in rounded-md px-3 py-2 text-sm whitespace-pre-line', 'self-start bg-black/30' => $message['role'] === 'assistant', 'self-end bg-tv-purple/40' => $message['role'] === 'user'])>{{ $message['content'] }}</li>
                            @endforeach
                            <li wire:loading wire:target="askCoach,startTailoring" class="animate-pulse self-start font-mono text-[11px] text-[#ffd37a]">orientador pensando…</li>
                            @if ($coachError)
                                <li class="text-[#ffd37a]">O LLM falhou: {{ $coachError }}</li>
                            @endif
                        </ol>

                        <form wire:submit="reply" class="flex shrink-0 flex-col gap-2 border-t border-white/10 p-2">
                            <textarea wire:model="answer" maxlength="2000" rows="2" placeholder="Responda com o que você realmente fez" aria-label="Resposta" class="w-full resize-none rounded bg-white/10 px-2.5 py-2 ring-tv-purple outline-none placeholder:text-tv-muted focus:bg-black focus:ring-2"></textarea>
                            @error('answer')
                                <p class="text-[#ff8280]">{{ $message }}</p>
                            @enderror
                            <div class="flex items-center justify-between gap-2">
                                <button type="button" wire:click="writeTailored" wire:loading.attr="disabled" wire:target="writeTailored,askCoach" @disabled(! $answered) class="h-[30px] rounded bg-[#34d399]/80 px-2.5 font-semibold text-tv-bg hover:bg-[#34d399] disabled:opacity-40">Gerar currículo</button>
                                <button type="submit" wire:loading.attr="disabled" wire:target="reply,askCoach" class="h-[30px] rounded bg-tv-purple px-2.5 font-semibold hover:bg-tv-purple-hover disabled:opacity-50">Responder</button>
                            </div>
                        </form>
                    </section>

                    {{-- Tailored resume --}}
                    <section class="flex min-h-0 flex-col rounded-md bg-tv-raised" aria-label="Currículo novo">
                        <header class="flex shrink-0 items-center justify-between gap-2 border-b border-white/10 px-3 py-2">
                            <h3 class="text-sm font-semibold">Currículo novo</h3>
                            <span class="rounded bg-[#34d399]/20 px-1.5 py-0.5 font-mono text-[11px] text-[#6ee7b7]">LLM escreve{{ $tailoredMs !== null ? ' · '.$milliseconds($tailoredMs) : '' }}</span>
                        </header>

                        <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-3">
                            <div class="shrink-0 rounded bg-black/25 px-3 py-2">
                                <p class="flex items-baseline justify-between gap-2">
                                    <span class="font-semibold">Aderência de cada rascunho</span>
                                    <span class="rounded bg-tv-purple/25 px-1.5 py-0.5 font-mono text-[11px] text-tv-link">decision · {{ $option['label'] }}</span>
                                </p>
                                <p class="mt-1 font-mono text-xs text-tv-muted tabular-nums">antes · {{ round($verdict['score']) }}/100</p>
                                <p wire:stream="measurements" class="font-mono text-xs whitespace-pre-line tabular-nums">@foreach ($measurements as $measurement)rascunho {{ $loop->iteration }} · {{ round($measurement['score']) }}/100 · {{ number_format($measurement['ms'], 0, ',', '.') }} ms{{ "\n" }}@endforeach</p>
                                <p wire:loading wire:target="writeTailored" class="animate-pulse font-mono text-[11px] text-[#ffd37a]">LLM escrevendo e medindo com o {{ $option['label'] }}…</p>
                            </div>

                            @if ($tailoredError)
                                <p class="text-[#ffd37a]">Falhou: {{ $tailoredError }}</p>
                            @endif

                            @if ($tailoredVerdict)
                                <div wire:loading.remove wire:target="writeTailored" @class(['flex shrink-0 animate-card-in items-center justify-between gap-3 rounded-md px-4 py-3', 'bg-[#34d399]/15 ring-1 ring-[#34d399]/50' => $tailoredVerdict['invite'], 'bg-tv-red/15 ring-1 ring-tv-red/50' => ! $tailoredVerdict['invite']])>
                                    <div>
                                        <p @class(['text-lg font-bold', 'text-[#6ee7b7]' => $tailoredVerdict['invite'], 'text-[#ff8280]' => ! $tailoredVerdict['invite']])>{{ $tailoredVerdict['invite'] ? 'Chamar para entrevista' : 'Não chamar' }}</p>
                                        <p class="font-mono text-[11px] text-tv-muted">versão final medida no código · mesmas regras</p>
                                    </div>
                                    <p class="text-2xl font-bold tabular-nums"><span class="text-base text-tv-muted">{{ round($verdict['score']) }} →</span> {{ round($tailoredVerdict['score']) }}<span class="text-base text-tv-muted">/100</span></p>
                                </div>
                                <button type="button" wire:click="downloadPdf" wire:loading.attr="disabled" wire:target="downloadPdf,writeTailored" class="h-[34px] shrink-0 rounded bg-tv-purple px-3 font-semibold hover:bg-tv-purple-hover disabled:opacity-50">Baixar PDF</button>
                            @endif

                            <pre wire:stream="tailored" class="min-h-40 flex-1 rounded bg-black/25 px-3 py-2 font-mono text-xs whitespace-pre-wrap">{{ $tailored }}</pre>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    @endif
</div>
