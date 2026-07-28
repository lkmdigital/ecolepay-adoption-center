<?php

namespace App\Domains\Activity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Entrée du journal d'activité (table `activity_log`), technique ou métier.
 */
class ActivityLog extends Model
{
    protected $table = 'activity_log';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
