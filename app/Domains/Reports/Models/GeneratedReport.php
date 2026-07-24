<?php

namespace App\Domains\Reports\Models;

use App\Domains\AI\Models\Diagnostic;
use App\Domains\Users\Models\User;
use App\Shared\Models\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Table `generated_reports`, grain : un rapport généré.
 *
 * Périmètre plus large que l'IA : la matrice de permissions distingue
 * `reports.generate` de `ai.generate`. `is_ai_generated` fait la distinction.
 *
 * Deux horloges distinctes : le fichier se supprime, la trace jamais — savoir qui a
 * exporté quelles données doit survivre à la destruction du fichier, la permission
 * `audit.view` reposant en partie dessus.
 */
class GeneratedReport extends Model
{
    protected $table = 'generated_reports';

    protected $fillable = [
        'report_key', 'title', 'scope_type', 'scope_id', 'scope_label',
        'period_start_date_id', 'period_end_date_id', 'parameters', 'format',
        'retention_class',
    ];

    protected $hidden = ['file_path', 'file_hash'];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'distribution' => 'array',
            'includes_personal_data' => 'boolean',
            'is_ai_generated' => 'boolean',
            'data_watermark_at' => 'datetime',
            'generated_at' => 'datetime',
            'file_deleted_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function diagnostic(): BelongsTo
    {
        return $this->belongsTo(Diagnostic::class, 'ai_diagnostic_id');
    }

    public function periodStart(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'period_start_date_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('generation_status', 'completed')->whereNull('file_deleted_at');
    }

    /**
     * Principale surface d'exposition : un fichier téléchargé échappe à tout
     * contrôle d'accès. Ces rapports sont purgés en priorité.
     */
    public function scopeSensitive(Builder $query): Builder
    {
        return $query->where('includes_personal_data', true);
    }

    public function scopeDueForPurge(Builder $query): Builder
    {
        return $query->whereNull('file_deleted_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function fileStillExists(): bool
    {
        return $this->file_path !== null && $this->file_deleted_at === null;
    }

    /**
     * `parameters` permet de reproduire le rapport à l'identique tant que les
     * données sources existent — c'est ce qui justifie une rétention de fichier
     * courte.
     */
    public function isRegenerable(): bool
    {
        return ! empty($this->parameters);
    }
}
