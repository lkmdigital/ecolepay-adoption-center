<?php

namespace App\Domains\Help\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Message du Centre d'aide (demande de support ou retour sur un article).
 */
class HelpMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['helpful' => 'boolean'];
    }
}
