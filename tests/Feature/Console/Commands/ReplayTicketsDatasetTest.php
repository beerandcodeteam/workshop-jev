<?php

use App\Ai\TicketTriage;
use App\Jobs\TriageTicket;
use App\Models\Ticket;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'triage.default' => 'decisions',
        'moderation.models' => [
            'llm' => ['label' => 'LLM de teste', 'driver' => 'agent', 'provider' => 'openai', 'model' => 'gpt-6-sol'],
            'decisions' => ['label' => 'Decisions de teste', 'driver' => 'decision', 'provider' => 'typesafe', 'model' => 'jev-latest'],
            'local' => ['label' => 'Local de teste', 'driver' => 'decision', 'provider' => 'laya', 'model' => 'multilingual'],
        ],
    ]);
});

it('queues the first tickets of the dataset for the given model', function () {
    Queue::fake([TriageTicket::class]);

    $this->artisan('tickets:replay', ['--model' => 'local', '--limit' => 3])->assertSuccessful();

    expect(Ticket::query()->where('model', 'local')->whereNull('triaged_at')->count())->toBe(3);
    Queue::assertPushed(TriageTicket::class, 3);
});

it('uses the default model and keeps the expected team and urgency of each ticket', function () {
    Queue::fake([TriageTicket::class]);

    $this->artisan('tickets:replay', ['--limit' => 1])->assertSuccessful();

    expect(Ticket::query()->sole())
        ->model->toBe('decisions')
        ->expected_team->toBeIn(array_keys(config('triage.teams')))
        ->expected_urgency->toBeIn([0, 1, 2])
        ->kind->toBeIn(['claro', 'misto', 'limiar', 'adversarial', 'contexto'])
        ->context->toBeNull();
});

it('fails without queueing anything when the model does not speak the decisions api', function (string $model) {
    Queue::fake([TriageTicket::class]);

    $this->artisan('tickets:replay', ['--model' => $model])->assertFailed();

    $this->assertDatabaseCount('tickets', 0);
    Queue::assertNotPushed(TriageTicket::class);
})->with(['llm', 'desconhecido']);

it('deletes the previous tickets when asked for a fresh inbox', function () {
    Queue::fake([TriageTicket::class]);
    $previous = Ticket::factory()->triaged()->create();

    $this->artisan('tickets:replay', ['--limit' => 1, '--fresh' => true])->assertSuccessful();

    $this->assertModelMissing($previous);
    $this->assertDatabaseCount('tickets', 1);
});

it('labels every ticket of the dataset with a configured team and urgency level', function () {
    $records = array_map(
        fn (string $line): array => json_decode($line, true),
        file(database_path('datasets/tickets.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
    );

    expect($records)->not->toBeEmpty()
        ->each(fn ($record) => $record
            ->team->toBeIn(array_keys(config('triage.teams')))
            ->urgency->toBeIn(array_keys(config('triage.urgency.levels'))));
});

it('sends the whole dataset when no limit is given', function () {
    Queue::fake([TriageTicket::class]);

    $this->artisan('tickets:replay')->assertSuccessful();

    $this->assertDatabaseCount('tickets', count(file(database_path('datasets/tickets.jsonl'), FILE_SKIP_EMPTY_LINES)));
});

it('sends the profile of each customer in the state when asked for context', function () {
    Queue::fake([TriageTicket::class]);

    $this->artisan('tickets:replay', ['--limit' => 1, '--context' => true])->assertSuccessful();

    $ticket = Ticket::query()->sole();

    expect($ticket->context)->toBe(TicketTriage::profile($ticket->customer))->not->toBeNull();
});

it('has a profile for the customer of every ticket of the dataset', function () {
    $customers = array_map(
        fn (string $line): string => json_decode($line, true)['customer'],
        file(database_path('datasets/tickets.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
    );

    expect(array_diff($customers, array_keys(TicketTriage::customers())))->toBe([])
        ->and(array_diff(config('triage.personas'), array_keys(TicketTriage::customers())))->toBe([]);
});

it('asks the job to retrieve similar examples only when told to', function (array $options, bool $retrieves) {
    Queue::fake([TriageTicket::class]);

    $this->artisan('tickets:replay', ['--limit' => 1, ...$options])->assertSuccessful();

    expect(Ticket::query()->sole()->retrieves)->toBe($retrieves);
})->with([
    'with examples' => [['--examples' => true], true],
    'without examples' => [[], false],
]);
