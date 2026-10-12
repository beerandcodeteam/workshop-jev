<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    config(['moderation.guidelines' => [
        'assedio' => ['label' => 'Assédio', 'block' => 'Xinga alguém.', 'allow' => 'Conversa normal.'],
        'spam_golpe' => ['label' => 'Spam e golpes', 'block' => 'Divulga ou aplica golpe.', 'allow' => 'Conversa normal.'],
    ]]);

    $this->path = storage_path('framework/testing/laya-'.uniqid());
});

afterEach(function () {
    File::deleteDirectory($this->path);
});

it('exports every training message with one labeled question per guideline', function () {
    $this->artisan('laya:export', ['--path' => $this->path])->assertSuccessful();

    $rows = collect(['train', 'valid'])
        ->flatMap(fn (string $name) => file("{$this->path}/{$name}.jsonl", FILE_IGNORE_NEW_LINES))
        ->map(fn (string $line) => json_decode($line, true));
    $messages = count(file(database_path('datasets/chat-messages-train.jsonl'), FILE_SKIP_EMPTY_LINES));

    expect($rows)->toHaveCount($messages)
        ->and($rows->first())->toHaveKeys(['state', 'questions', 'expected'])
        ->and($rows->first()['questions']['assedio'])->toBe([
            'type' => 'noul',
            'instructions' => 'Esta mensagem do chat de uma live viola a diretriz "Assédio"? Avalie o conteúdo, mesmo escrito com erros, números no lugar de letras, letras separadas ou em outro idioma.',
            'criteria' => ['true' => 'Xinga alguém.', 'false' => 'Conversa normal.'],
        ])
        ->and(array_keys($rows->first()['expected']))->toBe(['assedio', 'spam_golpe'])
        ->and($rows->first()['expected']['assedio'])->toBeBool();
});

it('holds out the asked share of the messages for validation', function () {
    $this->artisan('laya:export', ['--path' => $this->path, '--valid' => 20])->assertSuccessful();

    $train = count(file("{$this->path}/train.jsonl"));
    $valid = count(file("{$this->path}/valid.jsonl"));

    expect($valid / ($train + $valid))->toBeBetween(0.19, 0.21);
});
