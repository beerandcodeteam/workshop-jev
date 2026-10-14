<?php

use App\Jobs\EmbedTicketExample;
use App\Models\TicketExample;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

it('stores the embedding of the example', function () {
    Embeddings::fake();
    $example = TicketExample::factory()->create(['text' => 'Paguei e não liberou.']);

    EmbedTicketExample::dispatchSync($example);

    expect($example->refresh()->embedding)->toHaveCount(config('triage.examples.dimensions'));
    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['Paguei e não liberou.']);
});
