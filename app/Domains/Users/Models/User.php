<?php

namespace App\Domains\Users\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Schools\Models\School;
use Database\Factories\Users\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Dimension conforme : c'est la table d'authentification, utilisée telle quelle
 * comme dimension d'imputation.
 *
 * La suppression logique est structurante — sans elle, supprimer un utilisateur
 * effacerait la paternité de ses campagnes et de ses diagnostics IA, ce qui est
 * incompatible avec la permission `audit.view`. Un départ se traduit par une
 * désactivation.
 */
#[Fillable(['name', 'email', 'password', 'job_title', 'department', 'phone', 'locale', 'timezone', 'preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'preferences' => 'array',
        ];
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(\App\Domains\Users\Models\UserFavorite::class);
    }

    /**
     * Portefeuille commercial.
     */
    public function managedSchools(): HasMany
    {
        return $this->hasMany(School::class, 'account_manager_user_id');
    }

    public function createdCampaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'created_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInDepartment(Builder $query, string $department): Builder
    {
        return $query->where('department', $department);
    }

    /**
     * Désactivation plutôt que suppression : les faits qu'il a produits conservent
     * leur imputation.
     */
    public function deactivate(): bool
    {
        return $this->forceFill([
            'is_active' => false,
            'deactivated_at' => now(),
        ])->save();
    }

    public function recordLogin(): void
    {
        $this->forceFill([
            'last_login_at' => now(),
            'login_count' => $this->login_count + 1,
        ])->saveQuietly();
    }
}
