<?php

use App\Jobs\ModerateChatMessage;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Queue;

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
        ],
    ]]);
});

it('queues the first messages of the dataset for the given model', function () {
    Queue::fake([ModerateChatMessage::class]);

    $this->artisan('chat:replay', ['--model' => 'decisions', '--limit' => 3])->assertSuccessful();

    expect(ChatMessage::query()->where('model', 'decisions')->whereNull('moderated_at')->count())->toBe(3);
    Queue::assertPushed(ModerateChatMessage::class, 3);
});

it('uses the default model when none is given', function () {
    Queue::fake([ModerateChatMessage::class]);

    $this->artisan('chat:replay', ['--limit' => 1])->assertSuccessful();

    expect(ChatMessage::query()->sole())
        ->model->toBe('llm')
        ->expected->toBeBool()
        ->kind->toBeIn(['claro', 'ortografia', 'limiar', 'misto', 'adversarial']);
    Queue::assertPushed(ModerateChatMessage::class, 1);
});

it('fails without queueing anything when the model is not configured', function () {
    Queue::fake([ModerateChatMessage::class]);

    $this->artisan('chat:replay', ['--model' => 'desconhecido'])->assertFailed();

    $this->assertDatabaseCount('chat_messages', 0);
    Queue::assertNotPushed(ModerateChatMessage::class);
});

it('deletes the previous messages when asked for a fresh chat', function () {
    Queue::fake([ModerateChatMessage::class]);
    $previous = ChatMessage::factory()->approved()->create();

    $this->artisan('chat:replay', ['--limit' => 1, '--fresh' => true])->assertSuccessful();

    $this->assertModelMissing($previous);
    $this->assertDatabaseCount('chat_messages', 1);
});
