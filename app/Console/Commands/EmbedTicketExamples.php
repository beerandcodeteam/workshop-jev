<?php

namespace App\Console\Commands;

use App\Ai\TicketTriage;
use App\Models\TicketExample;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tickets:embed
    {--fresh : Generate the embedding of every example again, not only the missing ones}')]
#[Description('Load the labeled tickets of the dataset as examples and generate the missing embeddings')]
class EmbedTicketExamples extends Command
{
    /**
     * How many texts go in each embeddings request.
     */
    private const int Batch = 100;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $lines = file(database_path('datasets/tickets.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $record = json_decode($line, true);

            $example = TicketExample::query()->firstOrNew(['dataset_id' => $record['id']]);

            $example->fill([
                'source' => TicketExample::Dataset,
                'text' => $record['text'],
                'team' => $record['team'],
                'urgency' => $record['urgency'],
                'note' => $record['note'] ?? null,
            ]);

            if ($example->isDirty('text')) {
                $example->embedding = null;
            }

            $example->save();
        }

        $pending = TicketExample::query()
            ->when(! $this->option('fresh'), fn ($query) => $query->whereNull('embedding'))
            ->orderBy('id');

        $embedded = 0;

        $pending->chunkById(self::Batch, function ($examples) use (&$embedded): void {
            $embeddings = TicketTriage::embed($examples->pluck('text')->all());

            foreach ($examples->values() as $index => $example) {
                $example->update(['embedding' => $embeddings[$index]]);
            }

            $embedded += $examples->count();
        });

        $this->info(count($lines)." exemplos do dataset carregados, {$embedded} embeddings gerados.");

        return self::SUCCESS;
    }
}
