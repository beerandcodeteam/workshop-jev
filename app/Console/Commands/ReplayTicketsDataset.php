<?php

namespace App\Console\Commands;

use App\Ai\TicketTriage;
use App\Jobs\TriageTicket;
use App\Models\Ticket;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tickets:replay
    {--model= : Key of a decisions model in config/moderation.php (defaults to the one in config/triage.php)}
    {--limit= : How many tickets of the dataset to send (defaults to all of them)}
    {--context : Send the profile of each customer in the state, next to the ticket}
    {--examples : Retrieve the most similar labeled tickets into the state (run tickets:embed first)}
    {--fresh : Delete every ticket before sending}')]
#[Description('Send tickets of the triage dataset to the inbox through the queue')]
class ReplayTicketsDataset extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $model = $this->option('model') ?? TicketTriage::defaultModel();

        if (! array_key_exists($model, TicketTriage::models())) {
            $this->error("O modelo [{$model}] não existe em config/moderation.php ou não usa o driver decision.");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            Ticket::query()->delete();
        }

        $lines = file(database_path('datasets/tickets.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');

        foreach (array_slice($lines, 0, $limit) as $line) {
            $record = json_decode($line, true);

            $ticket = Ticket::create([
                'model' => $model,
                'customer' => $record['customer'],
                'text' => $record['text'],
                'context' => $this->option('context') ? TicketTriage::profile($record['customer']) : null,
                'retrieves' => (bool) $this->option('examples'),
                'expected_team' => $record['team'],
                'expected_urgency' => $record['urgency'],
                'kind' => $record['kind'],
            ]);

            TriageTicket::dispatch($ticket);
        }

        $this->info(Ticket::query()->where('model', $model)->whereNull('triaged_at')->count()." tickets na fila do modelo [{$model}].");

        return self::SUCCESS;
    }
}
