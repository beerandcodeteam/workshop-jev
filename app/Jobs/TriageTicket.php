<?php

namespace App\Jobs;

use App\Ai\TicketTriage;
use App\Models\Ticket;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Throwable;
use UnexpectedValueException;

#[DeleteWhenMissingModels]
class TriageTicket implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Ticket $ticket) {}

    /**
     * Asks the ticket's model every triage question in a single call, times it and saves the answers.
     * The job only stores what the model said: which column the ticket lands on and how it is
     * ranked are rules the board applies in plain code. A ticket the model could not answer
     * is left without a team, so a person routes it. A ticket that asks for examples first
     * retrieves the most similar labeled tickets, which go in the state next to it.
     */
    public function handle(): void
    {
        ['provider' => $provider, 'model' => $model] = TicketTriage::models()[$this->ticket->model];
        $startedAt = microtime(true);

        try {
            if ($this->ticket->retrieves) {
                $this->ticket->examples = TicketTriage::similarExamples($this->ticket);
            }

            $response = Classification::of(TicketTriage::state($this->ticket))->questions(TicketTriage::questions())->classify($provider, $model);

            $team = $response['team'];
            $urgency = $response['urgency'];
            $frustration = $response['frustration'];
            $refund = $response['refund'];

            if (! $team instanceof ChoiceAnswer || ! $urgency instanceof ScoreAnswer || ! $frustration instanceof ScoreAnswer || ! $refund instanceof BooleanAnswer) {
                throw new UnexpectedValueException('O modelo não respondeu todas as perguntas da triagem.');
            }

            $answers = [
                'team' => $team->choice,
                'team_probabilities' => $team->probabilities,
                'confidence' => $team->confidence,
                'urgency' => $urgency->score,
                'urgency_probabilities' => $urgency->probabilities,
                'frustration' => $frustration->score,
                'frustration_probabilities' => $frustration->probabilities,
                'refund' => $refund->probability,
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
            ];
        } catch (Throwable $exception) {
            report($exception);

            $answers = ['error' => $exception->getMessage()];
        }

        $this->ticket->update([
            ...$answers,
            'ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'triaged_at' => now(),
        ]);
    }

    /**
     * Sends the ticket to human review when the job dies before saving the answers, so it never stays queued forever.
     */
    public function failed(?Throwable $exception): void
    {
        $this->ticket->update(['error' => $exception?->getMessage() ?? 'O job falhou.', 'triaged_at' => now()]);
    }
}
