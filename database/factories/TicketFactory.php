<?php

namespace Database\Factories;

use App\Ai\TicketTriage;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * Define the model's default state: a ticket still waiting in the queue.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'model' => TicketTriage::defaultModel(),
            'customer' => fake()->userName(),
            'text' => fake()->sentence(),
        ];
    }

    /**
     * Indicate the team and the urgency level the dataset expects for the ticket.
     */
    public function expecting(string $team, int $urgency = 1, string $kind = 'claro'): static
    {
        return $this->state(fn (array $attributes) => [
            'expected_team' => $team,
            'expected_urgency' => $urgency,
            'kind' => $kind,
        ]);
    }

    /**
     * Indicate that the model sent the ticket to the given team, with the given confidence.
     */
    public function triaged(string $team = 'tecnico', float $confidence = 0.9): static
    {
        return $this->state(fn (array $attributes) => [
            'team' => $team,
            'team_probabilities' => [$team => 1.0],
            'confidence' => $confidence,
            'urgency' => 1.0,
            'urgency_probabilities' => [0.0, 1.0, 0.0],
            'frustration' => 1.0,
            'frustration_probabilities' => [0.0, 1.0, 0.0],
            'refund' => 0.05,
            'ms' => 400,
            'triaged_at' => now(),
        ]);
    }

    /**
     * Indicate that the model was unavailable and the ticket was left for a person.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'ms' => 400,
            'error' => 'Provider down.',
            'triaged_at' => now(),
        ]);
    }
}
