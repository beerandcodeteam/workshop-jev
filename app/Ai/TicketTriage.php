<?php

namespace App\Ai;

use App\Models\Ticket;
use App\Models\TicketExample;
use Laravel\Ai\Classification\Boolean as YesNoQuestion;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Embeddings;

class TicketTriage
{
    /**
     * The refund probability from which a ticket is flagged as a refund request.
     */
    public const float RefundFlag = 0.7;

    /**
     * The questions every ticket is triaged by, all answered in a single call.
     *
     * @return array{team: Choice, urgency: Score, frustration: Score, refund: YesNoQuestion}
     */
    public static function questions(): array
    {
        return [
            'team' => new Choice(
                'Qual time deve resolver este ticket de suporte de uma comunidade de cursos de programação?',
                array_map(fn (array $team): string => $team['description'], config('triage.teams')),
            ),
            'urgency' => new Score('Quão urgente é resolver este ticket?', config('triage.urgency.levels')),
            'frustration' => new Score('Quão frustrado o cliente parece neste ticket?', config('triage.frustration.levels')),
            'refund' => new YesNoQuestion('O cliente pede explicitamente reembolso ou estorno?', [
                'true' => 'Pede o dinheiro de volta, estorno ou reembolso.',
                'false' => 'Não pede dinheiro de volta, mesmo que reclame de uma cobrança.',
            ]),
        ];
    }

    /**
     * The models that can triage: the ones of config/moderation.php that speak the decisions API.
     *
     * @return array<string, array{label: string, driver: string, provider: string, model: string, input_price?: float, output_price?: float}>
     */
    public static function models(): array
    {
        return array_filter(config('moderation.models'), fn (array $model): bool => $model['driver'] === 'decision');
    }

    /**
     * The key of the model selected when none is given.
     */
    public static function defaultModel(): string
    {
        $models = self::models();

        return array_key_exists(config('triage.default'), $models) ? config('triage.default') : (string) array_key_first($models);
    }

    /**
     * The fictional customer profiles, keyed by customer.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function customers(): array
    {
        static $customers = null;

        return $customers ??= json_decode((string) file_get_contents(database_path('datasets/customers.json')), true);
    }

    /**
     * The profile of the given customer, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public static function profile(string $customer): ?array
    {
        return self::customers()[$customer] ?? null;
    }

    /**
     * What the model reads: the ticket alone, or the ticket next to the profile of its customer
     * and the most similar tickets a person already routed.
     *
     * @return string|array{ticket: string, cliente?: array<string, mixed>, tickets_parecidos_ja_resolvidos?: array<int, array<string, mixed>>}
     */
    public static function state(Ticket $ticket): string|array
    {
        $state = array_filter([
            'ticket' => $ticket->text,
            'cliente' => $ticket->context,
            'tickets_parecidos_ja_resolvidos' => $ticket->examples ?: null,
        ], fn (mixed $value): bool => $value !== null);

        return count($state) === 1 ? $ticket->text : $state;
    }

    /**
     * The embedding vector of each text, in the same order.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public static function embed(array $texts): array
    {
        return Embeddings::for($texts)->dimensions(config('triage.examples.dimensions'))->generate()->embeddings;
    }

    /**
     * The labeled tickets most similar to the given one, as the model reads them: the team and the urgency
     * go by the same descriptions the questions offer, so the model can match them to its options.
     *
     * @return array<int, array{ticket: string, time: string, urgencia: string|null, motivo: string|null, similaridade: float}>
     */
    public static function similarExamples(Ticket $ticket): array
    {
        [$embedding] = self::embed([$ticket->text]);

        return TicketExample::query()
            ->whereNotNull('embedding')
            ->where('text', '!=', $ticket->text)
            ->select(['text', 'team', 'urgency', 'note'])
            ->selectVectorDistance('embedding', $embedding, as: 'distance')
            ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: (float) config('triage.examples.min_similarity'))
            ->limit((int) config('triage.examples.limit'))
            ->get()
            ->map(fn (TicketExample $example): array => [
                'ticket' => $example->text,
                'time' => config("triage.teams.{$example->team}.description", $example->team),
                'urgencia' => $example->urgency === null ? null : config("triage.urgency.levels.{$example->urgency}"),
                'motivo' => $example->note,
                'similaridade' => round(1 - (float) $example->getAttribute('distance'), 2),
            ])
            ->all();
    }

    /**
     * Put a score on a scale from 0 to 1 by dividing it by its highest level.
     */
    public static function normalize(?float $score, string $question): float
    {
        $highest = count(config("triage.{$question}.levels")) - 1;

        return $highest > 0 ? ($score ?? 0.0) / $highest : 0.0;
    }
}
