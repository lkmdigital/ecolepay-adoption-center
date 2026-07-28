<?php

namespace App\Domains\Reports\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Définition d'un rapport : type, période, filtres et indicateurs. Le contenu est
 * re-rendu à la volée depuis les données réelles ; rien de figé n'est stocké.
 */
class Report extends Model
{
    protected $table = 'reports';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'indicators' => 'array',
            'schedule' => 'array',
            'is_favorite' => 'boolean',
            'last_generated_at' => 'datetime',
        ];
    }
}
