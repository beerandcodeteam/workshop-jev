<?php

namespace Database\Factories;

use App\Models\TicketExample;
use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Ai\Embeddings;

/**
 * @extends Factory<TicketExample>
 */
class TicketExampleFactory extends Factory
{
    /**
     * Define the model's default state: a dataset example still waiting for its embedding.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => TicketExample::Dataset,
            'dataset_id' => fake()->unique()->numberBetween(1, 100_000),
            'text' => fake()->sentence(),
            'team' => 'tecnico',
            'urgency' => 1,
            'note' => fake()->sentence(),
        ];
    }

    /**
     * Indicate the embedding of the example; a random unit vector when none is given.
     *
     * @param  array<int, float>|null  $embedding
     */
    public function embedded(?array $embedding = null): static
    {
        return $this->state(fn (array $attributes) => [
            'embedding' => $embedding ?? Embeddings::fakeEmbedding(config('triage.examples.dimensions')),
        ]);
    }
}
