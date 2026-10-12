<?php

namespace App\Console\Commands;

use App\Ai\Agents\ChatModerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Classification\Boolean;

#[Signature('laya:export
    {--path= : Directory to write to (defaults to storage/app/laya)}
    {--valid=10 : Percentage of the messages held out for validation}')]
#[Description('Export the training dataset in the format laya-train reads, with the questions the decisions API sends')]
class ExportLayaTraining extends Command
{
    /**
     * Execute the console command.
     *
     * Every row carries the same questions the moderation job sends, so the model is trained on
     * exactly what it will be asked: one yes / no question per guideline.
     */
    public function handle(): int
    {
        $questions = array_map(fn (Boolean $question): array => [
            'type' => 'noul',
            'instructions' => $question->instructions,
            'criteria' => $question->criteria,
        ], ChatModerator::questions());

        $every = (int) round(100 / max(1, (int) $this->option('valid')));
        $files = ['train' => [], 'valid' => []];

        foreach (file(database_path('datasets/chat-messages-train.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $index => $line) {
            $message = json_decode($line, true);

            $expected = [];

            foreach (array_keys($questions) as $guideline) {
                $expected[$guideline] = in_array($guideline, $message['violations'], true);
            }

            $files[$index % $every === 0 ? 'valid' : 'train'][] = json_encode(
                ['state' => $message['text'], 'questions' => $questions, 'expected' => $expected],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        $path = $this->option('path') ?? storage_path('app/laya');

        File::ensureDirectoryExists($path);

        foreach ($files as $name => $rows) {
            File::put("{$path}/{$name}.jsonl", implode("\n", $rows)."\n");

            $this->info(count($rows)." mensagens em {$path}/{$name}.jsonl");
        }

        return self::SUCCESS;
    }
}
