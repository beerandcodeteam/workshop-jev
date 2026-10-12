<?php

namespace App\Ai\Tools;

use App\Models\ChatMessage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class BanirUsuario implements Tool
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
        return 'Bane do chat o autor da mensagem. Só funciona quando ele já tem '.ChatMessage::StrikeLimit.' strikes.';
    }

    /**
     * Execute the tool, refusing to ban an author below the strike limit.
     */
    public function handle(Request $request): Stringable|string
    {
        $strikes = $this->message->strikes();

        if ($strikes < ChatMessage::StrikeLimit) {
            return "Recusado: {$this->message->user} tem {$strikes} de ".ChatMessage::StrikeLimit.' strikes.';
        }

        $this->message->update(['ban' => true]);

        return "{$this->message->user} foi banido do chat.";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'motivo' => $schema->string()->description('Motivo do banimento em uma frase curta.')->required(),
        ];
    }
}
