<?php

use App\Ai\Agents\ResumeCoach;
use App\Ai\Agents\ResumeReviewer;
use App\Ai\Agents\RubricWriter;
use App\Ai\Agents\TailoredResumeWriter;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Classification;
use Laravel\Ai\Gateway\FakeClassificationGateway;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Livewire\Livewire;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

beforeEach(function () {
    config([
        'screening.default' => 'decisions',
        'screening.invite_above' => 70,
        'screening.knockout_below' => 50,
        'screening.strength_above' => 70,
        'moderation.models' => [
            'llm' => ['label' => 'LLM de teste', 'driver' => 'agent', 'provider' => 'openai', 'model' => 'gpt-6-sol', 'input_price' => 0.10, 'output_price' => 0.50],
            'decisions' => ['label' => 'Decisions de teste', 'driver' => 'decision', 'provider' => 'typesafe', 'model' => 'jev-latest', 'input_price' => 0.042, 'output_price' => 0.0],
        ],
    ]);
});

/**
 * The criteria a job for a Laravel developer could get: one of each type, the yes / no one a knockout.
 *
 * @return list<array<string, mixed>>
 */
function laravelCriteria(): array
{
    return [
        ['key' => 'laravel', 'type' => 'noul', 'question' => 'Tem experiência com Laravel?', 'weight' => 3, 'knockout' => true, 'options' => [
            ['label' => 'sim', 'description' => 'Cita projetos em Laravel.', 'points' => 100],
            ['label' => 'não', 'description' => 'Não cita Laravel.', 'points' => 0],
        ]],
        ['key' => 'senioridade', 'type' => 'score', 'question' => 'Quanto tempo de experiência?', 'weight' => 2, 'knockout' => false, 'options' => [
            ['label' => 'júnior', 'description' => 'Menos de 2 anos.', 'points' => 20],
            ['label' => 'pleno', 'description' => 'De 2 a 5 anos.', 'points' => 70],
            ['label' => 'sênior', 'description' => 'Mais de 5 anos.', 'points' => 100],
        ]],
        ['key' => 'ingles', 'type' => 'choice', 'question' => 'Qual o nível de inglês?', 'weight' => 1, 'knockout' => false, 'options' => [
            ['label' => 'fluente', 'description' => 'Inglês fluente.', 'points' => 100],
            ['label' => 'básico', 'description' => 'Inglês básico.', 'points' => 30],
        ]],
    ];
}

/**
 * Fake the decision model's answers: a Laravel probability and the most likely seniority level.
 */
function fakeDecision(float $laravel, int $level = 2, ?float $then = null): FakeClassificationGateway
{
    return Classification::fake(array_map(fn (float $probability): array => decisionAnswers($probability, $level), $then === null ? [$laravel] : [$laravel, $then]));
}

/**
 * The decision model's answers for one resume.
 *
 * @return array<string, mixed>
 */
function decisionAnswers(float $laravel, int $level): array
{
    $levels = array_fill(0, 3, 0.0);
    $levels[$level] = 1.0;

    return [
        'laravel' => new BooleanAnswer($laravel),
        'senioridade' => new ScoreAnswer((float) $level, $levels, ['Menos de 2 anos.', 'De 2 a 5 anos.', 'Mais de 5 anos.']),
        'ingles' => new ChoiceAnswer('fluente', ['fluente' => 1.0, 'básico' => 0.0], confidence: 1.0),
    ];
}

it('renders the screening page and lists only the decision models', function () {
    $this->get('/screening')->assertOk()->assertSeeInOrder(['Vaga e critérios', 'Currículo', 'Resultado']);

    Livewire::test('pages::screening')
        ->assertSet('model', 'decisions')
        ->assertSee('Decisions de teste')
        ->assertDontSee('LLM de teste');
});

it('cleans the criteria the llm writes from the job description', function () {
    RubricWriter::fake([[
        'title' => 'Dev Laravel',
        'criteria' => [
            ...array_map(fn (array $criterion): array => [...$criterion, 'options' => array_reverse($criterion['options'])], array_slice(laravelCriteria(), 1, 1)),
            ...laravelCriteria(),
            ['key' => 'Laravel', 'type' => 'noul', 'question' => 'Usa Livewire?', 'weight' => 9, 'knockout' => false, 'options' => [
                ['label' => 'sim', 'description' => 'Cita Livewire.', 'points' => 100],
                ['label' => 'não', 'description' => 'Não cita.', 'points' => 0],
                ['label' => 'talvez', 'description' => 'Sobra.', 'points' => 50],
            ]],
            ['key' => 'vazio', 'type' => 'choice', 'question' => 'Só uma opção?', 'weight' => 1, 'knockout' => false, 'options' => [
                ['label' => 'única', 'description' => 'Única.', 'points' => 100],
            ]],
        ],
    ]]);

    $criteria = Livewire::test('pages::screening')
        ->set('job', 'Vaga de dev Laravel pleno.')
        ->call('writeRubric')
        ->assertHasNoErrors()
        ->assertSet('title', 'Dev Laravel')
        ->assertSee('Tem experiência com Laravel?')
        ->get('criteria');

    expect(array_column($criteria, 'key'))->toBe(['senioridade', 'laravel', 'senioridade_2', 'ingles', 'laravel_4'])
        ->and(array_column($criteria[0]['options'], 'label'))->toBe(['júnior', 'pleno', 'sênior'])
        ->and($criteria[4]['weight'])->toBe(3)
        ->and($criteria[4]['options'])->toHaveCount(2);

    RubricWriter::assertPrompted('Vaga de dev Laravel pleno.');
});

it('requires a job description before asking the llm', function () {
    RubricWriter::fake()->preventStrayPrompts();

    Livewire::test('pages::screening')
        ->set('job', '   ')
        ->call('writeRubric')
        ->assertHasErrors(['job' => 'required']);

    RubricWriter::assertNeverPrompted();
});

it('invites a resume that scores above the cut and passes every knockout', function () {
    fakeDecision(laravel: 0.9);

    Livewire::test('pages::screening')
        ->set('criteria', laravelCriteria())
        ->set('resumeText', 'Dev com 6 anos de Laravel, inglês fluente.')
        ->call('evaluate')
        ->assertHasNoErrors()
        ->assertSee('Chamar para entrevista')
        ->assertSeeInOrder(['Pontos fortes', 'com Laravel?', 'Pontos fracos', 'Nenhum.'])
        ->assertSet('results.laravel.points', 90.0)
        ->assertSet('results.senioridade.answer', 'sênior');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('6 anos de Laravel') && $prompt->asks('laravel') && $prompt->asks('ingles'));
});

it('rejects a resume that fails a knockout criterion even with a high score', function () {
    fakeDecision(laravel: 0.4);

    Livewire::test('pages::screening')
        ->set('criteria', laravelCriteria())
        ->set('inviteAbove', 50)
        ->set('resumeText', 'Dev Rails com 6 anos de experiência.')
        ->call('evaluate')
        ->assertSee('Não chamar')
        ->assertSee('reprovou eliminatório')
        ->assertSeeInOrder(['Pontos fracos', 'com Laravel?']);
});

it('changes the verdict when the cut moves, without asking the model again', function () {
    fakeDecision(laravel: 1.0, level: 1)->preventStrayClassifications();

    Livewire::test('pages::screening')
        ->set('criteria', laravelCriteria())
        ->set('resumeText', 'Dev Laravel com 3 anos.')
        ->call('evaluate')
        ->assertSee('Chamar para entrevista')
        ->set('inviteAbove', 95)
        ->assertSee('Não chamar');
});

it('streams the llm review told the decision', function () {
    fakeDecision(laravel: 0.9);
    ResumeReviewer::fake(['Pontos fortes: - Laravel há 6 anos.']);

    Livewire::test('pages::screening')
        ->set('job', 'Vaga de dev Laravel.')
        ->set('criteria', laravelCriteria())
        ->set('resumeText', 'Dev com 6 anos de Laravel.')
        ->call('evaluate')
        ->call('writeReview')
        ->assertSet('review', 'Pontos fortes: - Laravel há 6 anos.')
        ->assertSet('reviewError', null);

    ResumeReviewer::assertPrompted('Dev com 6 anos de Laravel.');
});

it('requires criteria and a resume before asking the decision model', function () {
    Classification::fake()->preventStrayClassifications();

    Livewire::test('pages::screening')
        ->call('evaluate')
        ->assertHasErrors(['criteria' => 'required', 'resumeText' => 'required'])
        ->assertSee('Gere os critérios da vaga primeiro.');

    Classification::assertNothingClassified();
});

it('shows the error and no verdict when the decision model fails', function () {
    Classification::fake(fn () => throw new RuntimeException('Provedor fora do ar.'));
    ResumeReviewer::fake()->preventStrayPrompts();

    Livewire::test('pages::screening')
        ->set('criteria', laravelCriteria())
        ->set('resumeText', 'Dev Laravel.')
        ->call('evaluate')
        ->assertSet('results', [])
        ->assertSee('O modelo de decisão falhou: Provedor fora do ar.')
        ->assertDontSee('Chamar para entrevista');

    ResumeReviewer::assertNeverPrompted();
});

it('extracts the text of an uploaded resume', function (string $extension, Closure $file) {
    Livewire::test('pages::screening')
        ->set('upload', $file())
        ->assertHasNoErrors()
        ->assertSet('fileName', "cv.{$extension}")
        ->assertSet('resumeText', "Maria Silva\nDev Laravel há 6 anos");
})->with([
    'text' => ['txt', fn () => UploadedFile::fake()->createWithContent('cv.txt', "Maria   Silva\n\n  Dev Laravel há 6 anos  ")],
    'word' => ['docx', function () {
        $word = new PhpWord;
        $section = $word->addSection();
        $section->addText('Maria Silva');
        $section->addText('Dev Laravel há 6 anos');
        $path = tempnam(sys_get_temp_dir(), 'cv');
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return UploadedFile::fake()->createWithContent('cv.docx', (string) file_get_contents($path));
    }],
]);

it('rejects files it cannot read', function () {
    Livewire::test('pages::screening')
        ->set('upload', UploadedFile::fake()->create('cv.png', 10, 'image/png'))
        ->assertHasErrors(['upload' => 'extensions'])
        ->assertSet('resumeText', '');
});

it('escapes html in the resume and the criteria', function () {
    $criteria = laravelCriteria();
    $criteria[0]['question'] = '<script>alert(1)</script>';

    Livewire::test('pages::screening')
        ->set('criteria', $criteria)
        ->assertSee('<script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('opens the tailoring interview with the coach first question', function () {
    fakeDecision(laravel: 0.3);
    ResumeCoach::fake(['Você já usou Laravel em algum projeto?']);

    Livewire::test('pages::screening')
        ->set('criteria', laravelCriteria())
        ->set('resumeText', 'Dev PHP com 3 anos.')
        ->call('evaluate')
        ->call('startTailoring')
        ->assertSet('tailoring', true)
        ->call('askCoach')
        ->assertSee('Você já usou Laravel em algum projeto?')
        ->assertDontSee('Comece a entrevista.');

    ResumeCoach::assertPrompted('Comece a entrevista.');
});

it('shows the candidate answer and asks the coach the next question', function () {
    fakeDecision(laravel: 0.3);
    ResumeCoach::fake(['Você já usou Laravel?', 'E inglês?']);

    $interview = Livewire::test('pages::screening')
        ->set('criteria', laravelCriteria())
        ->set('resumeText', 'Dev PHP com 3 anos.')
        ->call('evaluate')
        ->call('startTailoring')
        ->call('askCoach')
        ->set('answer', '  Fiz um SaaS em Laravel por 1 ano.  ')
        ->call('reply')
        ->assertSet('answer', '')
        ->call('askCoach')
        ->get('interview');

    expect(array_column($interview, 'content'))->toBe(['Comece a entrevista.', 'Você já usou Laravel?', 'Fiz um SaaS em Laravel por 1 ano.', 'E inglês?']);
    ResumeCoach::assertPrompted('Fiz um SaaS em Laravel por 1 ano.');
});

it('writes nothing before the candidate answers the coach', function () {
    fakeDecision(laravel: 0.3);
    TailoredResumeWriter::fake()->preventStrayPrompts();

    Livewire::test('pages::screening')
        ->set('criteria', laravelCriteria())
        ->set('resumeText', 'Dev PHP com 3 anos.')
        ->call('evaluate')
        ->call('startTailoring')
        ->call('writeTailored')
        ->assertSet('tailored', '');

    TailoredResumeWriter::assertNeverPrompted();
});

it('writes the tailored resume and measures the final version in code', function () {
    fakeDecision(laravel: 0.1, then: 0.95);
    TailoredResumeWriter::fake(["# Maria Silva\n\n## Experiência\n- Construí um SaaS em Laravel."]);

    Livewire::test('pages::screening')
        ->set('title', 'Dev Laravel')
        ->set('criteria', laravelCriteria())
        ->set('resumeText', 'Maria Silva, dev PHP.')
        ->call('evaluate')
        ->assertSee('Não chamar')
        ->set('interview', [['role' => 'assistant', 'content' => 'Já usou Laravel?'], ['role' => 'user', 'content' => 'Fiz um SaaS em Laravel.']])
        ->set('tailoring', true)
        ->call('writeTailored')
        ->assertSet('tailored', "# Maria Silva\n\n## Experiência\n- Construí um SaaS em Laravel.")
        ->assertSet('tailoredResults.laravel.points', 95.0)
        ->assertSee('versão final medida no código')
        ->call('downloadPdf')
        ->assertFileDownloaded('curriculo-dev-laravel.pdf', contentType: 'application/pdf');

    TailoredResumeWriter::assertPrompted('Maria Silva, dev PHP.');
    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('Construí um SaaS em Laravel.'));
});
