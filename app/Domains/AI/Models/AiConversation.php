<?php

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une conversation de l'Assistant IA.
 */
class AiConversation extends Model
{
    protected $guarded = [];

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('id');
    }
}
