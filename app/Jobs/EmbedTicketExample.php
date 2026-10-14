<?php

namespace App\Jobs;

use App\Ai\TicketTriage;
use App\Models\TicketExample;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

#[DeleteWhenMissingModels]
class EmbedTicketExample implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public TicketExample $example) {}

    /**
     * Generates the embedding of the example, so the triage can start retrieving it.
     */
    public function handle(): void
    {
        [$embedding] = TicketTriage::embed([$this->example->text]);

        $this->example->update(['embedding' => $embedding]);
    }
}
