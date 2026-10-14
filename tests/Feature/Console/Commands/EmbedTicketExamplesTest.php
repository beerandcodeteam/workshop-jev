<?php

use App\Models\TicketExample;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

it('loads every labeled ticket of the dataset as an example with its embedding', function () {
    Embeddings::fake();
    $lines = file(database_path('datasets/tickets.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    $this->artisan('tickets:embed')->assertSuccessful();

    expect(TicketExample::query()->where('source', TicketExample::Dataset)->whereNotNull('embedding')->count())->toBe(count($lines));
    $first = json_decode($lines[0], true);
    expect(TicketExample::query()->where('dataset_id', $first['id'])->sole())
        ->text->toBe($first['text'])
        ->team->toBe($first['team'])
        ->urgency->toBe($first['urgency']);
    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->dimensions === config('triage.examples.dimensions'));
});

it('only embeds the examples still missing an embedding, including the corrections of the review', function () {
    Embeddings::fake();
    $this->artisan('tickets:embed')->assertSuccessful();
    $correction = TicketExample::factory()->create(['source' => TicketExample::Review, 'dataset_id' => null, 'text' => 'Corrigido por uma pessoa.']);

    Embeddings::fake();
    $this->artisan('tickets:embed')->assertSuccessful();

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['Corrigido por uma pessoa.']);
    expect($correction->refresh()->embedding)->toHaveCount(config('triage.examples.dimensions'));
});
