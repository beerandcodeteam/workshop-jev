<?php

namespace App\Models;

use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A chat message and the moderation verdict it received from the queue.
 *
 * @property int $id
 * @property string $model The key of the moderation model in config/moderation.php.
 * @property string $user
 * @property string $text
 * @property bool|null $expected The verdict the dataset expects; null for messages typed in the chat.
 * @property string|null $kind The kind of dataset message: claro, ortografia, limiar, misto or adversarial.
 * @property bool|null $blocked Null while the message waits in the queue.
 * @property string|null $guideline The key of the violated guideline in config/moderation.php.
 * @property bool $strike Whether this message earned its author a strike.
 * @property bool $ban Whether moderating this message banned its author.
 * @property bool $skipped Whether the message was removed unmoderated because its author was already banned.
 * @property string|null $reason
 * @property float|null $probability
 * @property int|null $ms
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $error
 * @property Carbon|null $moderated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['model', 'user', 'text', 'expected', 'kind', 'blocked', 'guideline', 'strike', 'ban', 'skipped', 'reason', 'probability', 'ms', 'input_tokens', 'output_tokens', 'error', 'moderated_at'])]
class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

    /**
     * How many strikes get an author banned.
     */
    public const int StrikeLimit = 3;

    /**
     * The cache key of the switch that turns strikes and bans on or off for every moderator.
     */
    public const string StrikesSwitch = 'moderation.strikes';

    /**
     * Determine if moderators are giving strikes and banning authors.
     */
    public static function strikesEnabled(): bool
    {
        return (bool) Cache::get(self::StrikesSwitch, true);
    }

    /**
     * Count the strikes the author has with this message's model.
     */
    public function strikes(): int
    {
        return static::query()->where('model', $this->model)->where('user', $this->user)->where('strike', true)->count();
    }

    /**
     * Determine if this message's model has already banned the author.
     */
    public function authorIsBanned(): bool
    {
        return static::query()->where('model', $this->model)->where('user', $this->user)->where('ban', true)->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected' => 'boolean',
            'blocked' => 'boolean',
            'strike' => 'boolean',
            'ban' => 'boolean',
            'skipped' => 'boolean',
            'probability' => 'float',
            'moderated_at' => 'datetime',
        ];
    }
}
