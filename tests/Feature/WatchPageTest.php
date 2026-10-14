<?php

use App\Ai\Agents\ChatModerator;
use App\Jobs\ModerateChatMessage;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config(['moderation' => [
        'default' => 'llm',
        'guidelines' => [
            'assedio' => ['label' => 'Assédio', 'block' => 'Xinga alguém.', 'allow' => 'Conversa normal.'],
            'spam_golpe' => ['label' => 'Spam e golpes', 'block' => 'Divulga ou aplica golpe.', 'allow' => 'Conversa normal.'],
        ],
        'models' => [
            'llm' => ['label' => 'LLM de teste', 'driver' => 'agent', 'provider' => 'openai', 'model' => 'gpt-6-sol', 'input_price' => 0.10, 'output_price' => 0.50],
            'decisions' => ['label' => 'Decisions de teste', 'driver' => 'decision', 'provider' => 'typesafe', 'model' => 'jev-latest', 'input_price' => 0.042, 'output_price' => 0.0],
        ],
    ]]);
});

it('renders the stream page with the chat visible', function () {
    $this->get(route('watch'))
        ->assertOk()
        ->assertSee('Chat da transmissão')
        ->assertSee('Enviar uma mensagem');
});

it('shows a sent message right away, queues its moderation and clears the input', function () {
    Queue::fake([ModerateChatMessage::class]);

    Livewire::test('pages::watch')
        ->set('draft', '  Salve, chat!  ')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('draft', '')
        ->assertSee('Salve, chat!')
        ->assertSee('1 na fila');

    $this->assertDatabaseHas('chat_messages', ['model' => 'llm', 'user' => 'voce', 'text' => 'Salve, chat!', 'moderated_at' => null]);
    Queue::assertPushed(ModerateChatMessage::class, fn (ModerateChatMessage $job): bool => $job->message->text === 'Salve, chat!');
});

it('ignores blank messages', function () {
    Queue::fake([ModerateChatMessage::class]);

    Livewire::test('pages::watch')
        ->set('draft', '   ')
        ->call('send')
        ->assertHasNoErrors();

    $this->assertDatabaseCount('chat_messages', 0);
    Queue::assertNotPushed(ModerateChatMessage::class);
});

it('rejects messages longer than 500 characters', function () {
    Queue::fake([ModerateChatMessage::class]);

    Livewire::test('pages::watch')
        ->set('draft', str_repeat('a', 501))
        ->call('send')
        ->assertHasErrors(['draft' => 'max']);

    $this->assertDatabaseCount('chat_messages', 0);
    Queue::assertNotPushed(ModerateChatMessage::class);
});

it('shows the verdict of a sent message once the queue moderates it', function () {
    ChatModerator::fake([['reason' => 'Cumprimento normal de chat.', 'guideline' => 'nenhuma', 'blocked' => false]]);

    Livewire::test('pages::watch')
        ->set('draft', 'Salve, chat!')
        ->call('send')
        ->assertSee('Salve, chat!')
        ->assertSee('Liberada')
        ->assertSee('Cumprimento normal de chat.')
        ->assertDontSee('na fila');

    ChatModerator::assertPrompted('Salve, chat!');
});

it('escapes html sent to the chat', function () {
    ChatMessage::factory()->approved()->create(['text' => '<script>alert(1)</script>']);

    Livewire::test('pages::watch')
        ->assertSee('<script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('replaces a blocked message with a removal notice in the chat', function () {
    ChatMessage::factory()->blocked()->create(['model' => 'decisions', 'text' => 'compre seguidores no meu site']);

    Livewire::test('pages::watch')
        ->assertSet('model', 'llm')
        ->assertDontSee('compre seguidores no meu site')
        ->assertSee('mensagem removida pela moderação');
});

it('shows the verdict, the reasoning and the time of the latest decision', function () {
    ChatMessage::factory()->blocked()->create(['text' => 'compre seguidores no meu site', 'guideline' => 'spam_golpe', 'reason' => 'Divulgação de venda de seguidores.', 'ms' => 1234]);

    Livewire::test('pages::watch')
        ->assertSee('Bloqueada')
        ->assertSee('Spam e golpes')
        ->assertSee('compre seguidores no meu site')
        ->assertSee('Divulgação de venda de seguidores.')
        ->assertSee('1.234 ms');
});

it('shows the block probability of a decisions api verdict', function () {
    ChatMessage::factory()->blocked()->create(['model' => 'decisions', 'probability' => 0.97]);

    Livewire::test('pages::watch')
        ->call('selectModel', 'decisions')
        ->assertSee('97%')
        ->assertSee('Este modelo não devolve raciocínio');
});

it('shows a message that went through because the moderator was unavailable', function () {
    ChatMessage::factory()->failed()->create(['text' => 'Salve, chat!']);

    Livewire::test('pages::watch')
        ->assertSee('Indisponível')
        ->assertSee('Provider down.')
        ->assertSee('Salve, chat!');
});

it('lists the models and starts on the default one', function () {
    Livewire::test('pages::watch')
        ->assertSet('model', 'llm')
        ->assertSee('LLM de teste')
        ->assertSee('Decisions de teste');
});

it('keeps the results of each model when switching between them', function () {
    ChatMessage::factory()->blocked()->create(['model' => 'llm', 'text' => 'mensagem para o llm']);
    ChatMessage::factory()->blocked()->create(['model' => 'decisions', 'text' => 'mensagem para o decisions']);

    Livewire::test('pages::watch')
        ->assertSee('mensagem para o llm')
        ->assertDontSee('mensagem para o decisions')
        ->call('selectModel', 'decisions')
        ->assertSet('model', 'decisions')
        ->assertSee('mensagem para o decisions')
        ->assertDontSee('mensagem para o llm')
        ->call('selectModel', 'llm')
        ->assertSee('mensagem para o llm');
});

it('rejects a model that is not configured', function () {
    Livewire::test('pages::watch')
        ->call('selectModel', 'desconhecido')
        ->assertNotFound();
});

it('shows the totals of the selected model and averages only the answered calls', function () {
    ChatMessage::factory()->approved()->create(['ms' => 100]);
    ChatMessage::factory()->approved()->create(['ms' => 200]);
    ChatMessage::factory()->blocked()->create(['ms' => 600]);
    ChatMessage::factory()->failed()->create(['ms' => 9000]);
    ChatMessage::factory()->count(2)->create();
    ChatMessage::factory()->blocked()->create(['model' => 'decisions', 'ms' => 50]);

    Livewire::test('pages::watch')
        ->assertSeeInOrder(['moderadas:', '4', 'bloqueadas:', '1', '2 na fila'])
        ->assertSee('Média (3)')
        ->assertSee('300 ms');
});

it('shows the cost of the latest message and of every message of the selected model', function () {
    ChatMessage::factory()->approved()->create(['input_tokens' => 3000, 'output_tokens' => 600]);
    ChatMessage::factory()->approved()->create(['input_tokens' => 1000, 'output_tokens' => 200]);
    ChatMessage::factory()->approved()->create(['model' => 'decisions', 'input_tokens' => 9000]);

    Livewire::test('pages::watch')
        ->assertSee('US$ 0,000200')
        ->assertSee('US$ 0,0008');
});

it('shows the time between the first message sent and the last one moderated', function () {
    ChatMessage::factory()->approved()->create(['created_at' => '2026-01-01 10:00:00', 'moderated_at' => '2026-01-01 10:00:08']);
    ChatMessage::factory()->blocked()->create(['created_at' => '2026-01-01 10:00:01', 'moderated_at' => '2026-01-01 10:01:35']);
    ChatMessage::factory()->approved()->create(['model' => 'decisions', 'created_at' => '2026-01-01 09:00:00', 'moderated_at' => '2026-01-01 11:00:00']);

    Livewire::test('pages::watch')
        ->assertSee('Tempo da fila (2)')
        ->assertSee('1 min 35 s')
        ->assertSee('1 de 2');
});

it('shows the strikes of an author next to the name and removes the messages sent after the ban', function () {
    ChatMessage::factory()->blocked()->create(['user' => 'aprendiz', 'strike' => true]);
    ChatMessage::factory()->blocked()->count(3)->create(['user' => 'troll', 'strike' => true]);
    ChatMessage::factory()->blocked()->create(['user' => 'troll', 'strike' => true, 'ban' => true]);
    ChatMessage::factory()->create(['user' => 'troll', 'text' => 'ainda estou aqui', 'skipped' => true, 'moderated_at' => now()]);

    Livewire::test('pages::watch')
        ->assertSeeInOrder(['aprendiz', '1/3'])
        ->assertSeeInOrder(['troll', 'banido'])
        ->assertSee('mensagem removida: usuário banido')
        ->assertDontSee('ainda estou aqui');
});

it('compares how each model used strikes and bans against the authors that deserved a ban', function () {
    ChatMessage::factory()->blocked()->expecting(blocked: true)->count(2)->create(['user' => 'troll', 'strike' => true]);
    ChatMessage::factory()->blocked()->expecting(blocked: true)->create(['user' => 'troll', 'strike' => true, 'ban' => true]);
    ChatMessage::factory()->blocked()->expecting(blocked: true)->create(['user' => 'esquecido']);
    ChatMessage::factory()->approved()->expecting(blocked: false)->create(['user' => 'inocente', 'strike' => true]);
    ChatMessage::factory()->create(['user' => 'troll', 'skipped' => true, 'moderated_at' => now()]);

    Livewire::test('pages::watch')
        ->set('comparing', true)
        ->assertSeeInOrder(['Strikes e banimentos', 'LLM de teste', '4', '1', '1', '1', '1 de 1', '0', '0', '1']);
});

it('starts with strikes and bans off and switches them on and back off', function () {
    Livewire::test('pages::watch')
        ->assertSee('Desligado: só modera')
        ->call('toggleStrikes')
        ->assertSee('3 strikes banem o autor')
        ->call('toggleStrikes')
        ->assertSee('Desligado: só modera');

    expect(ChatMessage::strikesEnabled())->toBeFalse();
});

it('starts with strikes and bans on when the config says so', function () {
    config(['moderation.strikes' => true]);

    Livewire::test('pages::watch')->assertSee('3 strikes banem o autor');
});

it('keeps the model comparison closed until it is asked for', function () {
    ChatMessage::factory()->approved()->expecting(blocked: false)->create();

    Livewire::test('pages::watch')
        ->assertSee('Comparar modelos')
        ->assertDontSee('Acerto por tipo de mensagem')
        ->set('comparing', true)
        ->assertSee('Acerto por tipo de mensagem');
});

it('compares the accuracy of every model that ran against the expected verdicts', function () {
    ChatMessage::factory()->approved()->expecting(blocked: false)->create();
    ChatMessage::factory()->blocked()->expecting(blocked: true, kind: 'limiar')->create();
    ChatMessage::factory()->approved()->expecting(blocked: true, kind: 'limiar')->create(['text' => 'sei onde voce mora']);
    ChatMessage::factory()->failed()->expecting(blocked: true)->create();
    ChatMessage::factory()->approved()->create();
    ChatMessage::factory()->blocked()->expecting(blocked: false)->create(['model' => 'decisions', 'text' => 'que aula boa demais', 'probability' => 0.61]);

    Livewire::test('pages::watch')
        ->set('comparing', true)
        ->assertSee('2 de 3 (66,7%)')
        ->assertSee('1 de 2 (50,0%)')
        ->assertSee('0 de 1 (0,0%)')
        ->assertSee('bloqueou à toa')
        ->assertSeeInOrder(['deixou passar', 'limiar', 'sei onde voce mora'])
        ->assertSeeInOrder(['61%', 'que aula boa demais']);
});

it('says so when no model has run yet', function () {
    Livewire::test('pages::watch')
        ->set('comparing', true)
        ->assertSee('Nenhum modelo rodou ainda.');
});

it('keeps only the latest 80 messages in the chat', function () {
    ChatMessage::factory()->approved()->create(['model' => 'decisions', 'text' => 'mensagem antiga', 'moderated_at' => now()->subMinute()]);
    ChatMessage::factory()->approved()->count(80)->create();

    Livewire::test('pages::watch')->assertDontSee('mensagem antiga');
});
