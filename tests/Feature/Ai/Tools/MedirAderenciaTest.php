<?php

use App\Ai\ResumeScreening;
use App\Ai\Tools\MedirAderencia;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    config(['moderation.models' => [
        'decisions' => ['label' => 'Decisions de teste', 'driver' => 'decision', 'provider' => 'typesafe', 'model' => 'jev-latest'],
    ]]);

    $this->criteria = ResumeScreening::normalize([
        ['key' => 'laravel', 'type' => 'noul', 'question' => 'Tem experiência com Laravel?', 'weight' => 3, 'knockout' => true, 'options' => [
            ['label' => 'sim', 'description' => 'Cita Laravel.', 'points' => 100],
            ['label' => 'não', 'description' => 'Não cita.', 'points' => 0],
        ]],
        ['key' => 'ingles', 'type' => 'choice', 'question' => 'Qual o nível de inglês?', 'weight' => 1, 'knockout' => false, 'options' => [
            ['label' => 'fluente', 'description' => 'Fluente.', 'points' => 100],
            ['label' => 'básico', 'description' => 'Básico.', 'points' => 20],
        ]],
    ]);
});

it('measures a draft with the decision model and reports each criterion to the llm and the page', function () {
    Classification::fake([[
        'laravel' => new BooleanAnswer(0.8),
        'ingles' => new ChoiceAnswer('básico', ['fluente' => 0.0, 'básico' => 1.0], confidence: 1.0),
    ]]);
    $measured = [];

    $tool = new MedirAderencia($this->criteria, 'decisions', function (float $score) use (&$measured): void {
        $measured[] = $score;
    });

    $reply = (string) $tool->handle(new Request(['curriculo' => '# Maria\n- Laravel']));

    expect($reply)->toContain('Nota ponderada: 65 de 100.')
        ->toContain('Tem experiência com Laravel?: sim, 80 pontos')
        ->toContain('Qual o nível de inglês?: básico, 20 pontos')
        ->and($measured)->toBe([65.0])
        ->and($tool->scores)->toBe([65.0]);
});

it('tells the llm to deliver the draft when the decision model fails', function () {
    Classification::fake(fn () => throw new RuntimeException('Fora do ar.'));

    $tool = new MedirAderencia($this->criteria, 'decisions');

    expect((string) $tool->handle(new Request(['curriculo' => '# Maria'])))->toContain('Entregue o currículo como está.')
        ->and($tool->scores)->toBe([]);
});
