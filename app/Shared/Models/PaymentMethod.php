<?php

namespace App\Shared\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Moyens de paiement — table `dim_payment_methods`.
 *
 * Croiser les parents inscrits non adoptants avec les moyens proposés par leur
 * école révèle les blocages structurels de conversion : c'est la raison d'être
 * analytique de cette dimension.
 */
class PaymentMethod extends Model
{
    protected $table = 'dim_payment_methods';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_digital' => 'boolean',
            'is_instant' => 'boolean',
            'default_fee_percentage' => 'decimal:4',
            'default_fee_fixed' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInCountry(Builder $query, string $countryCode): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('country_code', $countryCode)
            ->orWhereNull('country_code'));
    }

    public function scopeMobileMoney(Builder $query): Builder
    {
        return $query->where('category', 'mobile_money');
    }
}
