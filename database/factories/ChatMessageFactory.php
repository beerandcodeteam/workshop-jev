<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    /**
     * Define the model's default state: a message still waiting in the queue.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'model' => config('moderation.default'),
            'user' => fake()->userName(),
            'text' => fake()->sentence(),
        ];
    }

    /**
     * Indicate the verdict the dataset expects for the message.
     */
    public function expecting(bool $blocked, string $kind = 'claro'): static
    {
        return $this->state(fn (array $attributes) => [
            'expected' => $blocked,
            'kind' => $kind,
        ]);
    }

    /**
     * Indicate that the moderator let the message through.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'blocked' => false,
            'ms' => 400,
            'moderated_at' => now(),
        ]);
    }

    /**
     * Indicate that the moderator blocked the message.
     */
    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'blocked' => true,
            'ms' => 400,
            'moderated_at' => now(),
        ]);
    }

    /**
     * Indicate that the moderator was unavailable and the message went through unchecked.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'blocked' => false,
            'ms' => 400,
            'error' => 'Provider down.',
            'moderated_at' => now(),
        ]);
    }
}
