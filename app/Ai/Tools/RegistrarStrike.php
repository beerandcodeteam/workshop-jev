<?php

namespace App\Ai\Tools;

use App\Models\ChatMessage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RegistrarStrike implements Tool
{
    /**
     * Create a new tool instance for the message being moderated.
     */
    public function __construct(public ChatMessage $message) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Registra um strike para o autor da mensagem bloqueada e devolve quantos strikes ele tem agora.';
    }

    /**
     * Execute the tool. The strike lives on the message, so calling it twice counts once.
     */
    public function handle(Request $request): Stringable|string
    {
        $this->message->update(['strike' => true]);

        return "Strike {$this->message->strikes()} de ".ChatMessage::StrikeLimit." registrado para {$this->message->user}.";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'diretriz' => $schema->string()->enum(array_keys(config('moderation.guidelines')))->description('A diretriz violada pela mensagem.')->required(),
        ];
    }
}
