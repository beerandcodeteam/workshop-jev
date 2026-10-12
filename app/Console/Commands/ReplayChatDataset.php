<?php

namespace App\Console\Commands;

use App\Jobs\ModerateChatMessage;
use App\Models\ChatMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('chat:replay
    {--model= : Key of the model in config/moderation.php (defaults to the selected one)}
    {--limit=50 : How many messages of the dataset to send}
    {--fresh : Delete every chat message before sending}')]
#[Description('Send messages of the moderation dataset to the chat through the queue')]
class ReplayChatDataset extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $models = config('moderation.models');
        $default = array_key_exists(config('moderation.default'), $models) ? config('moderation.default') : array_key_first($models);
        $model = $this->option('model') ?? $default;

        if (! array_key_exists($model, $models)) {
            $this->error("O modelo [{$model}] não existe em config/moderation.php.");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            ChatMessage::query()->delete();
        }

        $lines = file(database_path('datasets/chat-messages.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach (array_slice($lines, 0, (int) $this->option('limit')) as $line) {
            $record = json_decode($line, true);

            $message = ChatMessage::create([
                'model' => $model,
                'user' => $record['user'],
                'text' => $record['text'],
                'expected' => $record['blocked'],
                'kind' => $record['kind'],
            ]);

            ModerateChatMessage::dispatch($message);
        }

        $this->info(ChatMessage::query()->where('model', $model)->whereNull('moderated_at')->count()." mensagens na fila do modelo [{$model}].");

        return self::SUCCESS;
    }
}
