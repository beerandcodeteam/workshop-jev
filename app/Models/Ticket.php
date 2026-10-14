<?php

namespace App\Models;

use App\Ai\TicketTriage;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A support ticket and the triage answers it received from the queue.
 *
 * @property int $id
 * @property string $model The key of the triage model in config/moderation.php.
 * @property string $customer
 * @property string $text
 * @property array<string, mixed>|null $context The customer profile sent in the state next to the ticket; null when the ticket went alone.
 * @property bool $retrieves Whether the job retrieves the most similar labeled tickets into the state.
 * @property array<int, array<string, mixed>>|null $examples The similar tickets sent in the state; null until retrieved.
 * @property string|null $expected_team The team the dataset expects; null for tickets typed in the inbox.
 * @property int|null $expected_urgency The urgency level the dataset expects.
 * @property string|null $kind The kind of dataset ticket: claro, misto, limiar or adversarial.
 * @property string|null $team The key of the chosen team in config/triage.php; null while the ticket waits in the queue.
 * @property array<string, float>|null $team_probabilities The probability of each team.
 * @property float|null $confidence How peaked the team probabilities are, from 0 to 1.
 * @property float|null $urgency A position along the urgency levels, which can fall between two of them.
 * @property array<int, float>|null $urgency_probabilities The probability of each urgency level.
 * @property float|null $frustration A position along the frustration levels.
 * @property array<int, float>|null $frustration_probabilities The probability of each frustration level.
 * @property float|null $refund The probability that the customer asks for a refund.
 * @property int|null $ms
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $error
 * @property Carbon|null $triaged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['model', 'customer', 'text', 'context', 'retrieves', 'examples', 'expected_team', 'expected_urgency', 'kind', 'team', 'team_probabilities', 'confidence', 'urgency', 'urgency_probabilities', 'frustration', 'frustration_probabilities', 'refund', 'ms', 'input_tokens', 'output_tokens', 'error', 'triaged_at'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /**
     * The board column of tickets still waiting in the queue.
     */
    public const string Waiting = 'entrada';

    /**
     * The board column of tickets a person has to route.
     */
    public const string Review = 'revisao';

    /**
     * The board column the ticket belongs to: its team, unless the model failed
     * or was less confident than the given percentage.
     */
    public function column(int $reviewBelow): string
    {
        if ($this->triaged_at === null) {
            return self::Waiting;
        }

        if ($this->team === null || ($this->confidence ?? 0.0) * 100 < $reviewBelow) {
            return self::Review;
        }

        return $this->team;
    }

    /**
     * The priority from 0 to 1: urgency weighed by the given percentage and frustration by the rest.
     */
    public function priority(int $urgencyWeight): float
    {
        $weight = max(0, min(100, $urgencyWeight)) / 100;

        return $weight * TicketTriage::normalize($this->urgency, 'urgency')
            + (1 - $weight) * TicketTriage::normalize($this->frustration, 'frustration');
    }

    /**
     * Determine if the customer is likely asking for a refund.
     */
    public function asksRefund(): bool
    {
        return ($this->refund ?? 0.0) >= TicketTriage::RefundFlag;
    }

    /**
     * The example a person made of this ticket by correcting its team, if any.
     *
     * @return HasOne<TicketExample, $this>
     */
    public function correction(): HasOne
    {
        return $this->hasOne(TicketExample::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'retrieves' => 'boolean',
            'examples' => 'array',
            'team_probabilities' => 'array',
            'confidence' => 'float',
            'urgency' => 'float',
            'urgency_probabilities' => 'array',
            'frustration' => 'float',
            'frustration_probabilities' => 'array',
            'refund' => 'float',
            'triaged_at' => 'datetime',
        ];
    }
}
