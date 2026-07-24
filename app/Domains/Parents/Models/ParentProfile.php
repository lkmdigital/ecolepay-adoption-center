<?php

namespace App\Domains\Parents\Models;

use App\Domains\Schools\Models\Student;
use App\Shared\Concerns\ExcludesTestData;
use App\Shared\Models\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dimension parent — table `dim_parents`.
 *
 * Nommée `ParentProfile` et non `Parent` : `parent` est un mot réservé de PHP.
 *
 * `source_parent_id` est nullable par construction : un « parent connu » est un
 * numéro figurant dans la liste d'une école, sans compte EcolePay. Peupler cette
 * table seulement à l'inscription supprimerait le premier étage de l'entonnoir et
 * fausserait tous les taux de conversion par le dénominateur.
 */
class ParentProfile extends Model
{
    use ExcludesTestData, HasFactory;

    protected $table = 'dim_parents';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Données personnelles : masquées par défaut à la sérialisation. Les exports
     * nominatifs doivent les demander explicitement, et sont soumis à
     * `parents.export`.
     */
    protected $hidden = ['phone_e164', 'phone_hash', 'email', 'row_hash'];

    protected function casts(): array
    {
        return [
            'first_known_at' => 'datetime',
            'account_created_at' => 'datetime',
            'marketing_consent' => 'boolean',
            'marketing_consent_at' => 'datetime',
            'is_pseudonymized' => 'boolean',
            'pseudonymized_at' => 'datetime',
            'retention_until' => 'date',
            'is_test' => 'boolean',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function preferredChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'preferred_channel_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(
            Student::class,
            'bridge_student_parents',
            'parent_id',
            'student_id',
        )->withPivot(['relationship', 'is_primary_payer', 'valid_from', 'valid_to']);
    }

    public function journeys(): HasMany
    {
        return $this->hasMany(ParentJourney::class, 'parent_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'parent_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ParentActivity::class, 'parent_id');
    }

    public function adoptionEvents(): HasMany
    {
        return $this->hasMany(AdoptionEvent::class, 'parent_id');
    }

    /**
     * Numéro connu d'une école, sans compte EcolePay : le premier étage de
     * l'entonnoir.
     */
    public function scopeWithoutAccount(Builder $query): Builder
    {
        return $query->whereNull('source_parent_id');
    }

    public function scopeRegistered(Builder $query): Builder
    {
        return $query->whereNotNull('account_created_at');
    }

    public function scopeContactable(Builder $query): Builder
    {
        return $query->where('marketing_consent', true)->where('is_pseudonymized', false);
    }

    public function hasAccount(): bool
    {
        return $this->source_parent_id !== null;
    }
}
