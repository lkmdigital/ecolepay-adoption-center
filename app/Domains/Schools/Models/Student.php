<?php

namespace App\Domains\Schools\Models;

use App\Domains\Parents\Models\ParentProfile;
use App\Shared\Concerns\ExcludesTestData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Dimension élève — table `dim_students`.
 *
 * C'est l'élève qui relie le parent à l'école, jamais un lien direct : un parent
 * ayant des enfants dans deux écoles fausserait sinon les taux des deux
 * établissements.
 *
 * Une ligne par élève et par année scolaire, ce qui permet de suivre sa progression
 * sans écraser l'historique à chaque rentrée.
 */
class Student extends Model
{
    use ExcludesTestData, HasFactory;

    protected $table = 'dim_students';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'date',
            'left_at' => 'date',
            'is_test' => 'boolean',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    /**
     * Un élève a souvent deux responsables, et ce n'est pas toujours le principal
     * qui paie — d'où la table de liaison plutôt qu'une clé étrangère simple.
     */
    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(
            ParentProfile::class,
            'bridge_student_parents',
            'student_id',
            'parent_id',
        )->withPivot(['relationship', 'is_primary_payer', 'valid_from', 'valid_to']);
    }

    public function primaryPayer(): BelongsToMany
    {
        return $this->parents()->wherePivot('is_primary_payer', true);
    }

    public function scopeEnrolled(Builder $query): Builder
    {
        return $query->where('enrollment_status', 'enrolled');
    }

    public function scopeSchoolYear(Builder $query, string $label): Builder
    {
        return $query->where('school_year_label', $label);
    }

    /**
     * Tri par rang ordinal : les nomenclatures ne se trient pas alphabétiquement,
     * « CM2 » précède « 6e » et « Terminale » suit « 1re ».
     */
    public function scopeInLevelOrder(Builder $query): Builder
    {
        return $query->orderBy('level_rank');
    }
}
