<?php

namespace App\Domains\Analytics\Models;

use App\Domains\Campaigns\Models\Campaign;
use App\Shared\Models\CalendarDate;
use App\Shared\Models\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agrégat — table `agg_campaign_kpis`, grain : une campagne × une période.
 */
class CampaignKpi extends Model
{
    protected $table = 'agg_campaign_kpis';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attributed_amount' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'computed_at' => 'datetime',
            'source_watermark_at' => 'datetime',
            'is_final' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function periodStart(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'period_start_date_id');
    }

    public function scopeTotals(Builder $query): Builder
    {
        return $query->where('period_type', 'total');
    }

    public function scopeFinal(Builder $query): Builder
    {
        return $query->where('is_final', true);
    }

    /**
     * Le piège principal de cette table.
     *
     * Le dénominateur est `evaluated_count`, jamais `sent_count` : pendant la
     * fenêtre d'attribution aucune conversion n'est encore attribuée. Diviser par
     * les envois ferait apparaître toute campagne récente comme un échec total, et
     * inciterait à couper une campagne qui fonctionne.
     */
    public function conversionRate(): ?float
    {
        return $this->evaluated_count > 0
            ? round($this->attributed_conversion_count / $this->evaluated_count, 4)
            : null;
    }

    public function deliveryRate(): ?float
    {
        return $this->sent_count > 0
            ? round($this->delivered_count / $this->sent_count, 4)
            : null;
    }

    public function openRate(): ?float
    {
        return $this->delivered_count > 0
            ? round($this->opened_count / $this->delivered_count, 4)
            : null;
    }

    public function costPerConversion(): ?float
    {
        return $this->attributed_conversion_count > 0
            ? round((float) $this->total_cost / $this->attributed_conversion_count, 2)
            : null;
    }

    /**
     * À afficher, pas seulement à stocker : explique à l'utilisateur pourquoi le
     * taux de conversion est encore provisoire.
     */
    public function isStillPending(): bool
    {
        return $this->pending_evaluation_count > 0;
    }
}
