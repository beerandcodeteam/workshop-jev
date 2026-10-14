<?php

namespace App\Models;

use Database\Factories\TicketExampleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A ticket whose team a person confirmed, kept with its embedding so the triage can retrieve the most similar ones.
 *
 * @property int $id
 * @property string $source Where the label came from: the dataset or a person in the review.
 * @property int|null $dataset_id The id of the line in database/datasets/tickets.jsonl.
 * @property int|null $ticket_id The inbox ticket a person corrected.
 * @property string $text
 * @property string $team The key of the team in config/triage.php.
 * @property int|null $urgency The urgency level, when it is known.
 * @property string|null $note Why the ticket belongs to the team.
 * @property array<int, float>|null $embedding Null until the embedding is generated.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['source', 'dataset_id', 'ticket_id', 'text', 'team', 'urgency', 'note', 'embedding'])]
#[Hidden(['embedding'])]
class TicketExample extends Model
{
    /** @use HasFactory<TicketExampleFactory> */
    use HasFactory;

    /**
     * The source of the examples loaded from the dataset.
     */
    public const string Dataset = 'dataset';

    /**
     * The source of the examples a person corrected in the review.
     */
    public const string Review = 'revisao';

    /**
     * The inbox ticket a person corrected, if any.
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => AsVector::class,
        ];
    }
}
