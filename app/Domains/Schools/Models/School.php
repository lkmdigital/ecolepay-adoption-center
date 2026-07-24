<?php

namespace App\Domains\Schools\Models;

use App\Domains\Parents\Models\ParentJourney;
use App\Domains\Parents\Models\Payment;
use App\Domains\Users\Models\User;
use App\Shared\Concerns\ExcludesTestData;
use App\Shared\Concerns\HasCurrentVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dimension école — table `dim_schools`, historisée en type 2.
 *
 * Piège de l'historisation : les faits pointent vers une version précise, donc vers
 * un `id` figé. Analyser « les écoles telles qu'elles sont aujourd'hui » suppose de
 * joindre la ligne courante via `source_school_id`, pas la clé portée par le fait.
 */
class School extends Model
{
    use ExcludesTestData, HasCurrentVersion, HasFactory;

    protected $table = 'dim_schools';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'has_preschool' => 'boolean',
            'has_primary' => 'boolean',
            'has_secondary' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'onboarded_at' => 'date',
            'is_test' => 'boolean',
            'is_current' => 'boolean',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_user_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'school_id');
    }

    public function journeys(): HasMany
    {
        return $this->hasMany(ParentJourney::class, 'school_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'school_id');
    }

    public function dailySnapshots(): HasMany
    {
        return $this->hasMany(SchoolDailySnapshot::class, 'school_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeInRegion(Builder $query, string $region): Builder
    {
        return $query->where('region', $region);
    }

    /**
     * Portefeuille d'un commercial : c'est ce qui restreint le rôle Commercial
     * à ses propres écoles.
     */
    public function scopeManagedBy(Builder $query, User|int $user): Builder
    {
        return $query->where('account_manager_user_id', $user instanceof User ? $user->id : $user);
    }

    /**
     * Toutes les versions de la même école, historique compris.
     */
    public function scopeSameSource(Builder $query, string $sourceSchoolId): Builder
    {
        return $query->where('source_school_id', $sourceSchoolId);
    }
}
