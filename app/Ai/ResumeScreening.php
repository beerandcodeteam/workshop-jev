<?php

namespace App\Ai;

use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean as YesNoQuestion;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use UnexpectedValueException;

/**
 * A criterion is one question about a resume, written by the LLM from the job description. Each option is
 * worth from 0 to 100 points, and the decision model answers with the probability of each option, so the
 * criterion is worth the expected points. A "noul" criterion has two options, yes and no, in that order.
 *
 * @phpstan-type Option array{label: string, description: string, points: int}
 * @phpstan-type Criterion array{key: string, type: string, question: string, weight: int, knockout: bool, options: list<Option>}
 * @phpstan-type Result array{points: float, answer: string, probability: float, probabilities: array<string, float>}
 */
class ResumeScreening
{
    /**
     * The question types the LLM may write.
     */
    public const array Types = ['noul', 'choice', 'score'];

    /**
     * The decision models that can score a resume: the same ones that triage tickets.
     *
     * @return array<string, array{label: string, driver: string, provider: string, model: string, input_price?: float, output_price?: float}>
     */
    public static function models(): array
    {
        return TicketTriage::models();
    }

    /**
     * The key of the decision model selected when none is given.
     */
    public static function defaultModel(): string
    {
        $models = self::models();

        return array_key_exists(config('screening.default'), $models) ? config('screening.default') : (string) array_key_first($models);
    }

    /**
     * Clean the criteria the LLM wrote: unique snake_case keys, weights from 1 to 3, two options for a yes / no
     * question, levels sorted by points for a score and unique labels for a choice. Criteria that cannot become
     * a question are dropped.
     *
     * @param  array<int, mixed>  $criteria
     * @return list<Criterion>
     */
    public static function normalize(array $criteria): array
    {
        $normalized = [];

        foreach ($criteria as $index => $criterion) {
            if (! is_array($criterion) || ! in_array($criterion['type'] ?? null, self::Types, true) || trim((string) ($criterion['question'] ?? '')) === '') {
                continue;
            }

            $options = [];

            foreach ((array) ($criterion['options'] ?? []) as $option) {
                $label = trim((string) ($option['label'] ?? ''));

                if ($label !== '' && ! array_key_exists($label, $options)) {
                    $options[$label] = [
                        'label' => $label,
                        'description' => trim((string) ($option['description'] ?? '')) ?: $label,
                        'points' => max(0, min(100, (int) ($option['points'] ?? 0))),
                    ];
                }
            }

            $options = array_values($options);

            if ($criterion['type'] === 'noul') {
                $options = array_slice($options, 0, 2);
            }

            if ($criterion['type'] === 'score') {
                usort($options, fn (array $a, array $b): int => $a['points'] <=> $b['points']);
            }

            if (count($options) < 2) {
                continue;
            }

            $key = Str::snake(Str::ascii((string) ($criterion['key'] ?? ''))) ?: 'criterio';
            $key = array_key_exists($key, $normalized) ? "{$key}_{$index}" : $key;

            $normalized[$key] = [
                'key' => $key,
                'type' => $criterion['type'],
                'question' => trim((string) $criterion['question']),
                'weight' => max(1, min(3, (int) ($criterion['weight'] ?? 1))),
                'knockout' => (bool) ($criterion['knockout'] ?? false),
                'options' => $options,
            ];
        }

        return array_values($normalized);
    }

    /**
     * The criteria as decision questions, keyed by criterion, all answered in a single call.
     *
     * @param  list<Criterion>  $criteria
     * @return array<string, Question>
     */
    public static function questions(array $criteria): array
    {
        $questions = [];

        foreach ($criteria as $criterion) {
            $descriptions = array_column($criterion['options'], 'description', 'label');
            $question = "Sobre este currículo: {$criterion['question']}";

            $questions[$criterion['key']] = match ($criterion['type']) {
                'noul' => new YesNoQuestion($question, ['true' => $criterion['options'][0]['description'], 'false' => $criterion['options'][1]['description']]),
                'score' => new Score($question, array_values($descriptions)),
                default => new Choice($question, $descriptions),
            };
        }

        return $questions;
    }

    /**
     * Ask the decision model every criterion about a resume in a single call.
     *
     * @param  list<Criterion>  $criteria
     * @return array{results: array<string, Result>, input_tokens: int}
     *
     * @throws UnexpectedValueException if the model leaves a criterion unanswered.
     */
    public static function measure(array $criteria, string $resume, string $model): array
    {
        ['provider' => $provider, 'model' => $name] = self::models()[$model];

        $response = Classification::of(mb_substr($resume, 0, (int) config('screening.max_characters')))
            ->questions(self::questions($criteria))
            ->classify($provider, $name);

        $results = [];

        foreach ($criteria as $criterion) {
            $results[$criterion['key']] = self::result($criterion, $response->answer($criterion['key']));
        }

        return ['results' => $results, 'input_tokens' => $response->usage->inputTokens];
    }

    /**
     * Turn the decision model's answer into points: each option's points weighed by its probability.
     *
     * @param  Criterion  $criterion
     * @return Result
     *
     * @throws UnexpectedValueException if the answer does not match the criterion type.
     */
    public static function result(array $criterion, ?Answer $answer): array
    {
        $labels = array_column($criterion['options'], 'label');

        $probabilities = match (true) {
            $criterion['type'] === 'noul' && $answer instanceof BooleanAnswer => [$labels[0] => $answer->probability, $labels[1] => 1 - $answer->probability],
            $criterion['type'] === 'score' && $answer instanceof ScoreAnswer => array_combine($labels, array_map(fn (int $level): float => (float) ($answer->probabilities[$level] ?? 0.0), array_keys($labels))),
            $criterion['type'] === 'choice' && $answer instanceof ChoiceAnswer => array_combine($labels, array_map(fn (string $label): float => $answer->probabilityOf($label), $labels)),
            default => throw new UnexpectedValueException("O modelo não respondeu o critério [{$criterion['key']}]."),
        };

        $total = array_sum($probabilities);
        $points = 0.0;
        $answer = $labels[0];

        foreach ($criterion['options'] as $option) {
            $points += ($total > 0 ? $probabilities[$option['label']] / $total : 0.0) * $option['points'];

            if ($probabilities[$option['label']] > $probabilities[$answer]) {
                $answer = $option['label'];
            }
        }

        return ['points' => $points, 'answer' => $answer, 'probability' => $probabilities[$answer], 'probabilities' => $probabilities];
    }

    /**
     * The verdict, in plain code over the stored results: the weighted score, the knockout criteria that
     * failed, the strengths and the weaknesses. Changing a rule never asks the model again.
     *
     * @param  list<Criterion>  $criteria
     * @param  array<string, Result>  $results
     * @return array{invite: bool, score: float, failed: list<string>, strengths: list<string>, weaknesses: list<string>}
     */
    public static function verdict(array $criteria, array $results, int $inviteAbove, int $knockoutBelow, int $strengthAbove): array
    {
        $weighted = 0.0;
        $weights = 0;
        $failed = [];
        $points = [];

        foreach ($criteria as $criterion) {
            if (! array_key_exists($criterion['key'], $results)) {
                continue;
            }

            $points[$criterion['key']] = $results[$criterion['key']]['points'];
            $weighted += $points[$criterion['key']] * $criterion['weight'];
            $weights += $criterion['weight'];

            if ($criterion['knockout'] && $points[$criterion['key']] < $knockoutBelow) {
                $failed[] = $criterion['key'];
            }
        }

        $score = $weights > 0 ? $weighted / $weights : 0.0;

        arsort($points);
        $strengths = array_keys(array_filter($points, fn (float $value): bool => $value >= $strengthAbove));

        asort($points);
        $weaknesses = array_keys(array_filter($points, fn (float $value): bool => $value < $knockoutBelow));

        return [
            'invite' => $weights > 0 && $score >= $inviteAbove && $failed === [],
            'score' => $score,
            'failed' => $failed,
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
        ];
    }
}
