<?php

use App\Jobs\TriageTicket;
use App\Models\Ticket;
use App\Models\TicketExample;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Classification;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;

beforeEach(function () {
    config([
        'triage.default' => 'decisions',
        'moderation.models' => [
            'llm' => ['label' => 'LLM de teste', 'driver' => 'agent', 'provider' => 'openai', 'model' => 'gpt-6-sol'],
            'decisions' => ['label' => 'Decisions de teste', 'driver' => 'decision', 'provider' => 'typesafe', 'model' => 'jev-latest'],
        ],
    ]);
});

it('stores the answer of every triage question', function () {
    Classification::fake([[
        'team' => new ChoiceAnswer('cobranca', ['acesso' => 0.2, 'cobranca' => 0.8], 0.75),
        'urgency' => new ScoreAnswer(1.4, [0 => 0.0, 1 => 0.6, 2 => 0.4], [], 0.4),
        'frustration' => new ScoreAnswer(2.0, [0 => 0.0, 1 => 0.0, 2 => 1.0], [], 1.0),
        'refund' => new BooleanAnswer(0.93),
    ]]);
    $ticket = Ticket::factory()->create(['text' => 'Fui cobrado duas vezes, quero estorno.']);

    TriageTicket::dispatchSync($ticket);

    expect($ticket->refresh())
        ->team->toBe('cobranca')
        ->team_probabilities->toBe(['acesso' => 0.2, 'cobranca' => 0.8])
        ->confidence->toBe(0.75)
        ->urgency->toBe(1.4)
        ->urgency_probabilities->toEqual([0.0, 0.6, 0.4])
        ->frustration->toBe(2.0)
        ->refund->toBe(0.93)
        ->error->toBeNull()
        ->ms->toBeInt()
        ->triaged_at->not->toBeNull();
});

it('asks the four questions about the ticket in a single call', function () {
    Classification::fake();
    $ticket = Ticket::factory()->create(['text' => 'Esqueci minha senha.']);

    TriageTicket::dispatchSync($ticket);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('Esqueci minha senha.')
        && $prompt->count() === 4
        && $prompt->asks('team') && $prompt->asks('urgency') && $prompt->asks('frustration') && $prompt->asks('refund'));
});

it('leaves the ticket without a team and records the error when the model is unavailable', function () {
    Exceptions::fake();
    Classification::fake(fn () => throw new RuntimeException('Provider down.'));
    $ticket = Ticket::factory()->create();

    TriageTicket::dispatchSync($ticket);

    expect($ticket->refresh())
        ->team->toBeNull()
        ->confidence->toBeNull()
        ->error->toBe('Provider down.')
        ->triaged_at->not->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});

it('sends the ticket to human review when the job dies before saving the answers', function () {
    $ticket = Ticket::factory()->create();

    (new TriageTicket($ticket))->failed(new RuntimeException('Tempo esgotado.'));

    expect($ticket->refresh())
        ->error->toBe('Tempo esgotado.')
        ->triaged_at->not->toBeNull()
        ->column(60)->toBe(Ticket::Review);
});

it('sends the ticket alone when it has no customer profile', function () {
    Classification::fake();
    $ticket = Ticket::factory()->create(['text' => 'Não consigo entrar no curso.']);

    TriageTicket::dispatchSync($ticket);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->state === 'Não consigo entrar no curso.');
});

it('sends the customer profile in the state next to the ticket', function () {
    Classification::fake();
    $ticket = Ticket::factory()->create([
        'text' => 'Não consigo entrar no curso.',
        'context' => ['cliente' => true, 'pagamento' => 'atrasado há 12 dias'],
    ]);

    TriageTicket::dispatchSync($ticket);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->state === [
        'ticket' => 'Não consigo entrar no curso.',
        'cliente' => ['cliente' => true, 'pagamento' => 'atrasado há 12 dias'],
    ]);
});

it('retrieves the most similar labeled tickets into the state, never the ticket itself', function () {
    $direction = fn (float $first, float $second): array => [$first, $second, ...array_fill(0, config('triage.examples.dimensions') - 2, 0.0)];
    Embeddings::fake([[$direction(1.0, 0.0)]]);
    Classification::fake();
    TicketExample::factory()->embedded($direction(1.0, 0.0))->create(['text' => 'Não consigo entrar no curso.', 'team' => 'acesso']);
    TicketExample::factory()->embedded($direction(0.8, 0.6))->create(['text' => 'Minha senha não funciona.', 'team' => 'acesso', 'urgency' => 2, 'note' => 'Login.']);
    TicketExample::factory()->embedded($direction(0.0, 1.0))->create(['text' => 'Quero um desconto.', 'team' => 'comercial']);
    $ticket = Ticket::factory()->create(['text' => 'Não consigo entrar no curso.', 'retrieves' => true]);

    TriageTicket::dispatchSync($ticket);

    $examples = [[
        'ticket' => 'Minha senha não funciona.',
        'time' => config('triage.teams.acesso.description'),
        'urgencia' => config('triage.urgency.levels.2'),
        'motivo' => 'Login.',
        'similaridade' => 0.8,
    ]];
    expect($ticket->refresh()->examples)->toBe($examples);
    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->state === [
        'ticket' => 'Não consigo entrar no curso.',
        'tickets_parecidos_ja_resolvidos' => $examples,
    ]);
});

it('does not embed the ticket when it does not ask for examples', function () {
    Embeddings::fake();
    Classification::fake();
    TicketExample::factory()->embedded()->create();

    TriageTicket::dispatchSync(Ticket::factory()->create());

    Embeddings::assertNothingGenerated();
});
