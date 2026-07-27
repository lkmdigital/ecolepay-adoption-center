<?php

namespace App\Domains\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vue personnalisée du Laboratoire d'Analyses (dimension + mesures + graphique).
 */
class SavedAnalysis extends Model
{
    protected $table = 'saved_analyses';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['measures' => 'array'];
    }
}
