<?php

namespace App\Jobs;

use App\Ai\Agents\ChatModerator;
use App\Models\ChatMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;
use UnexpectedValueException;

#[DeleteWhenMissingModels]
class ModerateChatMessage implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public ChatMessage $message) {}

    /**
     * Asks the message's model for a verdict, times it and saves the result. A banned author is never
     * moderated again, and the message goes through unchecked when the moderator is unavailable.
     *
     * While strikes are switched on, agents punish through their tools. The decisions API only answers one question per guideline
     * and the moderation endpoint only scores its own fixed categories, so for them the strike rule runs here, in plain code.
     */
    public function handle(): void
    {
        $strikes = ChatMessage::strikesEnabled();

        if ($strikes && $this->message->authorIsBanned()) {
            $this->message->update(['skipped' => true, 'moderated_at' => now()]);

            return;
        }

        ['driver' => $driver, 'provider' => $provider, 'model' => $model] = config("moderation.models.{$this->message->model}");
        $startedAt = microtime(true);

        try {
            if ($driver === 'moderation') {
                /** @var array{flagged: bool, category_scores: array<string, float>} $result */
                $result = Http::withToken(config("ai.providers.{$provider}.key"))
                    ->timeout(30)
                    ->post(config("ai.providers.{$provider}.url").'/moderations', ['model' => $model, 'input' => $this->message->text])
                    ->throw()
                    ->json('results.0');

                $scores = $result['category_scores'];
                arsort($scores);
                $category = (string) array_key_first($scores);

                $verdict = ['blocked' => $result['flagged'], 'guideline' => $result['flagged'] ? $category : null, 'probability' => $scores[$category]];
            } elseif ($driver === 'decision') {
                $questions = ChatModerator::questions();
                $response = Classification::of($this->message->text)->questions($questions)->classify($provider, $model);

                $probabilities = [];
                $refused = [];

                foreach (array_keys($questions) as $key) {
                    $answer = $response->answers[$key] ?? null;

                    if ($answer instanceof BooleanAnswer) {
                        $probabilities[$key] = $answer->probability;
                    } else {
                        $refused[] = ChatModerator::guidelines()[$key]['label'];
                    }
                }

                if ($probabilities === []) {
                    throw new UnexpectedValueException('O modelo não respondeu nenhuma diretriz.');
                }

                arsort($probabilities);
                $guideline = (string) array_key_first($probabilities);
                $blocked = $probabilities[$guideline] >= 0.5;

                $verdict = ['blocked' => $blocked, 'guideline' => $blocked ? $guideline : null, 'probability' => $probabilities[$guideline]];
                $verdict += ['reason' => $refused === [] ? null : 'O modelo se recusou a responder: '.implode(', ', $refused).'.'];
                $verdict += ['input_tokens' => $response->usage->inputTokens, 'output_tokens' => $response->usage->outputTokens];
            } else {
                /** @var StructuredAgentResponse $response */
                $response = (new ChatModerator($this->message))->prompt($this->message->text, provider: $provider, model: $model);
                $blocked = (bool) $response['blocked'];

                $verdict = ['blocked' => $blocked, 'guideline' => $blocked ? $response['guideline'] : null, 'reason' => $response['reason']];
                $verdict += ['input_tokens' => $response->usage->inputTokens, 'output_tokens' => $response->usage->outputTokens];
            }
        } catch (Throwable $exception) {
            report($exception);

            $verdict = ['blocked' => false, 'error' => $exception->getMessage()];
        }

        $this->message->update([
            ...$verdict,
            'ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'moderated_at' => now(),
        ]);

        if ($strikes && $driver !== 'agent' && $verdict['blocked']) {
            $this->punish();
        }
    }

    /**
     * The strike rule the agents follow through tools: a strike per blocked message, a ban at the limit.
     */
    private function punish(): void
    {
        $this->message->update(['strike' => true]);

        if ($this->message->strikes() >= ChatMessage::StrikeLimit) {
            $this->message->update(['ban' => true]);
        }
    }

    /**
     * Lets the message through when the job dies before saving a verdict, so it never stays queued forever.
     */
    public function failed(?Throwable $exception): void
    {
        $this->message->update(['blocked' => false, 'error' => $exception?->getMessage() ?? 'O job falhou.', 'moderated_at' => now()]);
    }
}
