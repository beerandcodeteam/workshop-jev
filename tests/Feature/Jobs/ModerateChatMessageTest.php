<?php

use App\Ai\Agents\ChatModerator;
use App\Ai\Tools\BanirUsuario;
use App\Ai\Tools\RegistrarStrike;
use App\Jobs\ModerateChatMessage;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    config(['moderation' => [
        'default' => 'llm',
        'guidelines' => [
            'assedio' => ['label' => 'Assédio', 'block' => 'Xinga alguém.', 'allow' => 'Conversa normal.'],
            'spam_golpe' => ['label' => 'Spam e golpes', 'block' => 'Divulga ou aplica golpe.', 'allow' => 'Conversa normal.'],
        ],
        'models' => [
            'llm' => ['label' => 'LLM de teste', 'driver' => 'agent', 'provider' => 'openai', 'model' => 'gpt-6-sol'],
            'decisions' => ['label' => 'Decisions de teste', 'driver' => 'decision', 'provider' => 'typesafe', 'model' => 'jev-latest'],
            'endpoint' => ['label' => 'Moderation de teste', 'driver' => 'moderation', 'provider' => 'openai', 'model' => 'omni-moderation-latest'],
        ],
    ]]);
});

it('approves a message with the reasoning of the agent', function () {
    ChatModerator::fake([['reason' => 'Cumprimento normal de chat.', 'guideline' => 'nenhuma', 'blocked' => false]]);
    $message = ChatMessage::factory()->create(['text' => 'Salve, chat!']);

    ModerateChatMessage::dispatchSync($message);

    expect($message->refresh())
        ->blocked->toBeFalse()
        ->reason->toBe('Cumprimento normal de chat.')
        ->guideline->toBeNull()
        ->error->toBeNull()
        ->ms->toBeInt()
        ->moderated_at->not->toBeNull();
    ChatModerator::assertPrompted('Salve, chat!');
});

it('blocks a message the agent rejects and stores the violated guideline', function () {
    ChatModerator::fake([['reason' => 'Divulgação de venda de seguidores.', 'guideline' => 'spam_golpe', 'blocked' => true]]);
    $message = ChatMessage::factory()->create(['text' => 'compre seguidores no meu site']);

    ModerateChatMessage::dispatchSync($message);

    expect($message->refresh())
        ->blocked->toBeTrue()
        ->guideline->toBe('spam_golpe')
        ->reason->toBe('Divulgação de venda de seguidores.');
});

it('blocks through the decisions api by the most likely guideline', function () {
    ChatModerator::fake();
    Classification::fake([['assedio' => new BooleanAnswer(0.12), 'spam_golpe' => new BooleanAnswer(0.97)]]);
    $message = ChatMessage::factory()->create(['model' => 'decisions', 'text' => 'compre seguidores no meu site']);

    ModerateChatMessage::dispatchSync($message);

    expect($message->refresh())
        ->blocked->toBeTrue()
        ->guideline->toBe('spam_golpe')
        ->probability->toBe(0.97)
        ->reason->toBeNull();
    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('compre seguidores'));
    ChatModerator::assertNeverPrompted();
});

it('gives a strike for each message the decisions api blocks and bans the author at the third', function () {
    Classification::fake(fn () => ['assedio' => new BooleanAnswer(0.9), 'spam_golpe' => new BooleanAnswer(0.1)]);
    $messages = ChatMessage::factory()->count(3)->create(['model' => 'decisions', 'user' => 'troll']);

    $messages->each(fn (ChatMessage $message) => ModerateChatMessage::dispatchSync($message));

    expect($messages->map->refresh()->pluck('strike')->all())->toBe([true, true, true])
        ->and($messages->pluck('ban')->all())->toBe([false, false, true]);
});

it('removes the message of a banned author without asking the moderator', function () {
    ChatModerator::fake();
    ChatMessage::factory()->blocked()->create(['user' => 'troll', 'strike' => true, 'ban' => true]);
    $message = ChatMessage::factory()->create(['user' => 'troll']);

    ModerateChatMessage::dispatchSync($message);

    expect($message->refresh())
        ->skipped->toBeTrue()
        ->blocked->toBeNull()
        ->moderated_at->not->toBeNull();
    ChatModerator::assertNeverPrompted();
});

it('blocks without strikes or bans while they are switched off', function () {
    Cache::forever(ChatMessage::StrikesSwitch, false);
    Classification::fake(fn () => ['assedio' => new BooleanAnswer(0.9), 'spam_golpe' => new BooleanAnswer(0.1)]);
    ChatMessage::factory()->blocked()->create(['model' => 'decisions', 'user' => 'troll', 'strike' => true, 'ban' => true]);
    $message = ChatMessage::factory()->create(['model' => 'decisions', 'user' => 'troll']);

    ModerateChatMessage::dispatchSync($message);

    expect($message->refresh())
        ->blocked->toBeTrue()
        ->strike->toBeFalse()
        ->skipped->toBeFalse();
});

it('gives the agent its tools only while strikes are switched on', function () {
    $agent = new ChatModerator(ChatMessage::factory()->create());

    expect($agent->tools())->toHaveCount(2)
        ->and($agent->instructions())->toContain('RegistrarStrike');

    Cache::forever(ChatMessage::StrikesSwitch, false);

    expect($agent->tools())->toBe([])
        ->and($agent->instructions())->not->toContain('RegistrarStrike');
});

it('keeps the bans of each model apart', function () {
    ChatModerator::fake([['reason' => 'Cumprimento normal de chat.', 'guideline' => 'nenhuma', 'blocked' => false]]);
    ChatMessage::factory()->blocked()->create(['model' => 'decisions', 'user' => 'troll', 'strike' => true, 'ban' => true]);
    $message = ChatMessage::factory()->create(['model' => 'llm', 'user' => 'troll']);

    ModerateChatMessage::dispatchSync($message);

    expect($message->refresh())->skipped->toBeFalse()->blocked->toBeFalse();
});

it('counts a strike once however many times the agent registers it', function () {
    $message = ChatMessage::factory()->create(['user' => 'troll']);
    $tool = new RegistrarStrike($message);

    $tool->handle(new Request(['diretriz' => 'assedio']));
    $result = $tool->handle(new Request(['diretriz' => 'assedio']));

    expect((string) $result)->toBe('Strike 1 de 3 registrado para troll.')
        ->and($message->refresh()->strike)->toBeTrue();
});

it('refuses to ban an author below three strikes', function () {
    ChatMessage::factory()->blocked()->create(['user' => 'troll', 'strike' => true]);
    $message = ChatMessage::factory()->create(['user' => 'troll', 'strike' => true]);

    $result = (new BanirUsuario($message))->handle(new Request(['motivo' => 'Reincidente.']));

    expect((string) $result)->toBe('Recusado: troll tem 2 de 3 strikes.')
        ->and($message->refresh()->ban)->toBeFalse();
});

it('bans an author with three strikes', function () {
    ChatMessage::factory()->blocked()->count(2)->create(['user' => 'troll', 'strike' => true]);
    $message = ChatMessage::factory()->create(['user' => 'troll', 'strike' => true]);

    $result = (new BanirUsuario($message))->handle(new Request(['motivo' => 'Reincidente.']));

    expect((string) $result)->toBe('troll foi banido do chat.')
        ->and($message->refresh()->authorIsBanned())->toBeTrue();
});

it('blocks what the moderation endpoint flags and stores its most likely category', function () {
    Http::preventStrayRequests();
    Http::fake(['*/moderations' => Http::response(['results' => [['flagged' => true, 'category_scores' => ['harassment' => 0.91, 'violence' => 0.12]]]])]);
    $message = ChatMessage::factory()->create(['model' => 'endpoint', 'user' => 'troll', 'text' => 'você é um lixo']);

    ModerateChatMessage::dispatchSync($message);

    expect($message->refresh())
        ->blocked->toBeTrue()
        ->guideline->toBe('harassment')
        ->probability->toBe(0.91)
        ->strike->toBeTrue()
        ->error->toBeNull();
    Http::assertSent(fn ($request): bool => $request['model'] === 'omni-moderation-latest' && $request['input'] === 'você é um lixo');
});

it('lets through what the moderation endpoint does not flag', function () {
    Http::preventStrayRequests();
    Http::fake(['*/moderations' => Http::response(['results' => [['flagged' => false, 'category_scores' => ['harassment' => 0.04, 'violence' => 0.01]]]])]);
    $message = ChatMessage::factory()->create(['model' => 'endpoint']);

    ModerateChatMessage::dispatchSync($message);

    expect($message->refresh())
        ->blocked->toBeFalse()
        ->guideline->toBeNull()
        ->probability->toBe(0.04);
});

it('lets the message through when the decisions api finds no guideline likely violated', function () {
    Classification::fake([['assedio' => new BooleanAnswer(0.31), 'spam_golpe' => new BooleanAnswer(0.08)]]);
    $message = ChatMessage::factory()->create(['model' => 'decisions']);

    ModerateChatMessage::dispatchSync($message);

    expect($message->refresh())
        ->blocked->toBeFalse()
        ->guideline->toBeNull()
        ->probability->toBe(0.31);
});

it('lets the message through and records the error when the moderator is unavailable', function () {
    Exceptions::fake();
    ChatModerator::fake(fn () => throw new RuntimeException('Provider down.'));
    $message = ChatMessage::factory()->create();

    ModerateChatMessage::dispatchSync($message);

    expect($message->refresh())
        ->blocked->toBeFalse()
        ->error->toBe('Provider down.')
        ->moderated_at->not->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});
