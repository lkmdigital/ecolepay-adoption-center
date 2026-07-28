<?php

namespace App\Domains\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Notification / alerte du centre de supervision (table `eac_notifications`).
 */
class Notification extends Model
{
    protected $table = 'eac_notifications';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
