<?php

use App\Ai\TicketTriage;
use App\Jobs\TriageTicket;
use App\Models\Ticket;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Classification;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'triage.default' => 'decisions',
        'triage.review_below' => 60,
        'triage.urgency_weight' => 60,
        'moderation.models' => [
            'llm' => ['label' => 'LLM de teste', 'driver' => 'agent', 'provider' => 'openai', 'model' => 'gpt-6-sol', 'input_price' => 0.10, 'output_price' => 0.50],
            'decisions' => ['label' => 'Decisions de teste', 'driver' => 'decision', 'provider' => 'typesafe', 'model' => 'jev-latest', 'input_price' => 0.042, 'output_price' => 0.0],
            'local' => ['label' => 'Local de teste', 'driver' => 'decision', 'provider' => 'laya', 'model' => 'multilingual', 'input_price' => 0.0, 'output_price' => 0.0],
        ],
    ]);
});

it('renders the board with a column for the queue, each team and human review', function () {
    $this->get('/inbox')
        ->assertOk()
        ->assertSeeInOrder(['Entrada', 'Acesso', 'Cobrança', 'Técnico', 'Comercial', 'Outro', 'Revisão humana']);
});

it('lists only the models that speak the decisions api and starts on the default one', function () {
    Livewire::test('pages::inbox')
        ->assertSet('model', 'decisions')
        ->assertSee('Decisions de teste')
        ->assertSee('Local de teste')
        ->assertDontSee('LLM de teste');
});

it('shows a sent ticket in the queue right away, queues its triage and clears the input', function () {
    Queue::fake([TriageTicket::class]);

    Livewire::test('pages::inbox')
        ->set('draft', '  Preciso de ajuda com meu login.  ')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('draft', '')
        ->assertSeeInOrder(['Entrada', 'Preciso de ajuda com meu login.', 'na fila', 'Acesso']);

    $this->assertDatabaseHas('tickets', ['model' => 'decisions', 'customer' => 'voce', 'text' => 'Preciso de ajuda com meu login.', 'triaged_at' => null]);
    Queue::assertPushed(TriageTicket::class, fn (TriageTicket $job): bool => $job->ticket->text === 'Preciso de ajuda com meu login.');
});

it('ignores blank tickets', function () {
    Queue::fake([TriageTicket::class]);

    Livewire::test('pages::inbox')
        ->set('draft', '   ')
        ->call('send')
        ->assertHasNoErrors();

    $this->assertDatabaseCount('tickets', 0);
    Queue::assertNotPushed(TriageTicket::class);
});

it('rejects tickets longer than 1000 characters', function () {
    Queue::fake([TriageTicket::class]);

    Livewire::test('pages::inbox')
        ->set('draft', str_repeat('a', 1001))
        ->call('send')
        ->assertHasErrors(['draft' => 'max']);

    $this->assertDatabaseCount('tickets', 0);
    Queue::assertNotPushed(TriageTicket::class);
});

it('escapes html sent in a ticket', function () {
    Ticket::factory()->triaged()->create(['text' => '<script>alert(1)</script>']);

    Livewire::test('pages::inbox')
        ->assertSee('<script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('puts a ticket in the column of the team the model chose', function () {
    Ticket::factory()->triaged('cobranca')->create(['text' => 'Fui cobrado duas vezes.']);

    Livewire::test('pages::inbox')
        ->assertSeeInOrder(['Cobrança', 'Fui cobrado duas vezes.', 'Técnico'])
        ->assertDontSee('palpite:');
});

it('sends a ticket to human review when the team confidence is below the cut', function () {
    Ticket::factory()->triaged('cobranca', confidence: 0.4)->create(['text' => 'Paguei e o acesso caiu.']);

    Livewire::test('pages::inbox')
        ->assertSeeInOrder(['Revisão humana', 'Paguei e o acesso caiu.', 'palpite: Cobrança']);
});

it('moves tickets to human review when the cut is raised, without asking the model again', function () {
    Classification::fake()->preventStrayClassifications();
    Queue::fake([TriageTicket::class]);
    Ticket::factory()->triaged('acesso', confidence: 0.7)->create(['text' => 'O convite do Discord expirou.']);

    Livewire::test('pages::inbox')
        ->assertSeeInOrder(['Acesso', 'O convite do Discord expirou.', 'Outro'])
        ->assertDontSee('palpite:')
        ->set('reviewBelow', 80)
        ->assertSeeInOrder(['Outro', 'O convite do Discord expirou.', 'palpite: Acesso']);

    Classification::assertNothingClassified();
    Queue::assertNotPushed(TriageTicket::class);
});

it('leaves a ticket the model could not answer for a person', function () {
    Ticket::factory()->failed()->create(['text' => 'Preciso de ajuda.']);

    Livewire::test('pages::inbox')
        ->assertSeeInOrder(['Revisão humana', 'Preciso de ajuda.', 'Indisponível']);
});

it('orders a column by priority and reorders it when the weights change', function () {
    Ticket::factory()->triaged('acesso')->create(['text' => 'urgente e calmo', 'urgency' => 2.0, 'frustration' => 0.0]);
    Ticket::factory()->triaged('acesso')->create(['text' => 'sem pressa e irritado', 'urgency' => 0.0, 'frustration' => 2.0]);

    Livewire::test('pages::inbox')
        ->assertSeeInOrder(['urgente e calmo', 'sem pressa e irritado'])
        ->set('urgencyWeight', 20)
        ->assertSeeInOrder(['sem pressa e irritado', 'urgente e calmo']);
});

it('flags a ticket only when a refund request is likely', function (float $refund, bool $flagged) {
    Ticket::factory()->triaged('cobranca')->create(['refund' => $refund]);

    $page = Livewire::test('pages::inbox');

    $flagged ? $page->assertSee('pede reembolso') : $page->assertDontSee('pede reembolso');
})->with([
    'likely' => [0.7, true],
    'unlikely' => [0.69, false],
]);

it('keeps the board rules inside the range of their sliders', function () {
    Livewire::test('pages::inbox')
        ->set('reviewBelow', 500)
        ->assertSet('reviewBelow', 100)
        ->set('urgencyWeight', -20)
        ->assertSet('urgencyWeight', 0);
});

it('keeps the tickets of each model when switching between them', function () {
    Ticket::factory()->triaged()->create(['text' => 'ticket para o decisions']);
    Ticket::factory()->triaged()->create(['model' => 'local', 'text' => 'ticket para o local']);

    Livewire::test('pages::inbox')
        ->assertSee('ticket para o decisions')
        ->assertDontSee('ticket para o local')
        ->call('selectModel', 'local')
        ->assertSet('model', 'local')
        ->assertSee('ticket para o local')
        ->assertDontSee('ticket para o decisions');
});

it('rejects a model that does not speak the decisions api', function (string $model) {
    Livewire::test('pages::inbox')
        ->call('selectModel', $model)
        ->assertNotFound();
})->with(['llm', 'desconhecido']);

it('shows the answer of each question of a ticket when it is opened', function () {
    $ticket = Ticket::factory()->triaged('acesso', confidence: 0.42)->expecting('outro')->create([
        'text' => 'Paguei e o acesso caiu.',
        'team_probabilities' => ['acesso' => 0.61, 'cobranca' => 0.35, 'tecnico' => 0.04],
        'urgency' => 1.4,
        'refund' => 0.12,
        'ms' => 382,
        'input_tokens' => 640,
        'output_tokens' => 80,
    ]);

    Livewire::test('pages::inbox')
        ->assertDontSee('Qual time resolve?')
        ->call('inspect', $ticket->id)
        ->assertSeeInOrder(['Qual time resolve?', '0,61', '0,35', '0,04'])
        ->assertSee('1,40 · Atrapalha')
        ->assertSeeInOrder(['Pede reembolso?', '0,12'])
        ->assertSee('0,42 · revisão')
        ->assertSee('Outro · não bateu')
        ->assertSee('382 ms · 640 → 80');
});

it('refuses to open a ticket of another model', function () {
    $ticket = Ticket::factory()->triaged()->create(['model' => 'local']);

    Livewire::test('pages::inbox')
        ->call('inspect', $ticket->id)
        ->assertNotFound();
});

it('compares the chosen team with the expected one, overall and among the tickets routed without a person', function () {
    Ticket::factory()->triaged('acesso', confidence: 0.9)->expecting('acesso')->create();
    Ticket::factory()->triaged('cobranca', confidence: 0.9)->expecting('acesso')->create();
    Ticket::factory()->triaged('tecnico', confidence: 0.3)->expecting('acesso')->create();
    Ticket::factory()->triaged('outro', confidence: 0.9)->create();
    Ticket::factory()->failed()->expecting('acesso')->create();
    Ticket::factory()->create();

    Livewire::test('pages::inbox')
        ->assertSeeInOrder(['Triados', '5 · 1 na fila'])
        ->assertSeeInOrder(['Time certo', '1 de 3 (33,3%)'])
        ->assertSeeInOrder(['Certo no automático', '1 de 2 (50,0%)'])
        ->assertSeeInOrder(['Sem revisão', '3 de 4 (75,0%)'])
        ->assertSeeInOrder(['Em revisão', '2']);
});

it('shows the cost of the answered tickets of the selected model', function () {
    Ticket::factory()->triaged()->create(['input_tokens' => 1000]);
    Ticket::factory()->triaged()->create(['input_tokens' => 2000]);
    Ticket::factory()->triaged()->create(['model' => 'local', 'input_tokens' => 9000]);

    Livewire::test('pages::inbox')->assertSee('US$ 0,000126');
});

it('sends a typed ticket with the profile of the chosen persona in the state', function () {
    Queue::fake([TriageTicket::class]);

    Livewire::test('pages::inbox')
        ->set('persona', 'ana.lima')
        ->set('draft', 'Não consigo entrar no curso.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('+perfil');

    expect(Ticket::query()->sole())
        ->customer->toBe('ana.lima')
        ->context->toBe(TicketTriage::profile('ana.lima'));
});

it('rejects a persona that is not configured', function () {
    Queue::fake([TriageTicket::class]);

    Livewire::test('pages::inbox')
        ->set('persona', 'intruso')
        ->set('draft', 'Oi')
        ->call('send')
        ->assertHasErrors(['persona' => 'in']);

    $this->assertDatabaseCount('tickets', 0);
});

it('shows the state sent to the model when the ticket carried a profile', function () {
    $ticket = Ticket::factory()->triaged('cobranca')->create([
        'model' => 'decisions',
        'text' => 'Não consigo entrar no curso.',
        'context' => ['pagamento' => 'atrasado há 12 dias'],
    ]);

    Livewire::test('pages::inbox')
        ->call('inspect', $ticket->id)
        ->assertSee('State enviado ao modelo')
        ->assertSee('atrasado há 12 dias');
});
