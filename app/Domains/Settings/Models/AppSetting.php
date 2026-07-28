<?php

namespace App\Domains\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Réglage de plateforme (table `app_settings`), une ligne par clé.
 */
class AppSetting extends Model
{
    protected $table = 'app_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
