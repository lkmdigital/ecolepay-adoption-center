<?php

namespace App\Domains\Parents\Models;

use App\Domains\Parents\Enums\PaymentStatus;
use App\Domains\Schools\Models\School;
use App\Domains\Schools\Models\Student;
use App\Shared\Concerns\ExcludesTestData;
use App\Shared\Models\CalendarDate;
use App\Shared\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fait transactionnel — table `fact_payments`, grain : une tentative de paiement.
 *
 * Porte le fait qui définit la conversion. Les échecs sont conservés
 * délibérément : un premier paiement échoué est le signal le plus actionnable du
 * dispositif.
 *
 * `$guarded = []` : table écrite exclusivement par l'ETL, jamais depuis une
 * requête HTTP.
 */
class Payment extends Model
{
    use ExcludesTestData;

    protected $table = 'fact_payments';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'is_first_payment' => 'boolean',
            'is_test' => 'boolean',
            'source_created_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ParentProfile::class, 'parent_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function date(): BelongsTo
    {
        return $this->belongsTo(CalendarDate::class, 'date_id');
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Success);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Failed);
    }

    /**
     * `is_first_payment` est une déduction, pas une donnée source : une transaction
     * arrivée en retard peut se révéler antérieure à celle déjà marquée. Recalculer
     * sur la fenêtre de reprise, jamais figer à l'insertion.
     */
    public function scopeFirstPayments(Builder $query): Builder
    {
        return $query->where('is_first_payment', true);
    }

    /**
     * Premiers paiements échoués : la meilleure liste d'appels du Commercial.
     */
    public function scopeBlockedConversions(Builder $query): Builder
    {
        return $query->where('is_first_payment', true)->where('status', PaymentStatus::Failed);
    }

    public function scopeBetween(Builder $query, int $fromDateKey, int $toDateKey): Builder
    {
        return $query->whereBetween('date_id', [$fromDateKey, $toDateKey]);
    }
}
