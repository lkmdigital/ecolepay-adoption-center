<?php

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un message d'une conversation de l'Assistant IA (rôle user | assistant).
 */
class AiMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
