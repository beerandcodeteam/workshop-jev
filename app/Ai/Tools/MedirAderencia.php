<?php

namespace App\Ai\Tools;

use App\Ai\ResumeScreening;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Lets the LLM writing a resume ask the decision model how well a draft fits the job, as often as it revises.
 *
 * @phpstan-import-type Criterion from ResumeScreening
 */
class MedirAderencia implements Tool
{
    /**
     * The weighted score of each measured draft, in order.
     *
     * @var list<float>
     */
    public array $scores = [];

    /**
     * Create a new tool instance for the job's criteria.
     *
     * @param  list<Criterion>  $criteria
     * @param  (Closure(float, array<string, array{points: float, answer: string, probability: float, probabilities: array<string, float>}>, int): void)|null  $onMeasure  Called with the score, the results and the milliseconds of each measurement.
     */
    public function __construct(
        public array $criteria,
        public string $model,
        public ?Closure $onMeasure = null,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Mede a aderência de um rascunho de currículo à vaga com o modelo de decisão e devolve a nota de 0 a 100 e os pontos de cada critério.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $startedAt = microtime(true);

        try {
            ['results' => $results] = ResumeScreening::measure($this->criteria, (string) $request['curriculo'], $this->model);
        } catch (Throwable $exception) {
            report($exception);

            return 'O modelo de decisão falhou nesta medição. Entregue o currículo como está.';
        }

        $verdict = ResumeScreening::verdict($this->criteria, $results, 0, (int) config('screening.knockout_below'), (int) config('screening.strength_above'));
        $this->scores[] = $verdict['score'];

        if ($this->onMeasure) {
            ($this->onMeasure)($verdict['score'], $results, (int) round((microtime(true) - $startedAt) * 1000));
        }

        $lines = ['Nota ponderada: '.round($verdict['score']).' de 100.'];

        foreach ($this->criteria as $criterion) {
            $lines[] = sprintf('- %s: %s, %d pontos', $criterion['question'], $results[$criterion['key']]['answer'], round($results[$criterion['key']]['points']));
        }

        return implode("\n", $lines);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'curriculo' => $schema->string()->description('O rascunho completo do currículo em Markdown.')->required(),
        ];
    }
}
