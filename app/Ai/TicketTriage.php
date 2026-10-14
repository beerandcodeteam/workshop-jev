<?php

namespace App\Ai;

use App\Models\Ticket;
use Laravel\Ai\Classification\Boolean as YesNoQuestion;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;

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
     * What the model reads: the ticket alone, or the ticket next to the profile of its customer.
     *
     * @return string|array{ticket: string, cliente: array<string, mixed>}
     */
    public static function state(Ticket $ticket): string|array
    {
        return $ticket->context === null ? $ticket->text : ['ticket' => $ticket->text, 'cliente' => $ticket->context];
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
