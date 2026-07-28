<?php

namespace App\Domains\Users\Support;

use App\Domains\Users\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Résout « l'utilisateur courant » de la plateforme.
 *
 * Tant que l'authentification n'est pas posée (pas de login, table `users`
 * initialement vide), il n'y a pas de session : on retombe sur un compte de
 * référence unique, créé une seule fois et clairement marqué comme provisoire.
 * Dès que l'authentification arrivera (module Utilisateurs & rôles), `auth()`
 * prendra le relais sans changer les appelants.
 */
final class CurrentUser
{
    /** Préférences personnelles par défaut d'un compte. */
    public const PREFERENCE_DEFAULTS = [
        'language' => 'fr',
        'theme' => 'light',
        'density' => 'normale',
        'default_dashboard' => 'dashboard',
        'notif_types' => ['alertes', 'rapports', 'adoption'],
        'notif_channels' => ['interface'],
        'notif_frequency' => 'immediate',
        'ai_briefing' => true,
    ];

    public static function resolve(): User
    {
        if ($user = auth()->user()) {
            return $user;
        }

        $user = User::query()->orderBy('id')->first();

        if (! $user) {
            $user = User::query()->create([
                'name' => 'Utilisateur EAC',
                'email' => 'compte@adoption-center.local',
                'password' => null,
                'job_title' => 'Direction',
                'department' => 'Direction',
                'phone' => null,
                'locale' => 'fr',
                'timezone' => 'Africa/Abidjan',
                'is_active' => true,
                'last_login_at' => now(),
                'login_count' => 1,
            ]);

            // Rôle par défaut, seulement si la matrice de rôles est déjà en base.
            if (Schema::hasTable('roles')) {
                try {
                    $user->assignRole('direction');
                } catch (\Throwable $e) {
                    // Rôles pas encore semés : on n'interrompt pas la résolution.
                }
            }
        }

        return $user;
    }

    /**
     * Lecture seule du compte courant, sans le créer. Pour l'affichage (menu,
     * avatar) où l'on ne veut pas d'effet de bord d'écriture.
     */
    public static function peek(): ?User
    {
        return auth()->user() ?? User::query()->orderBy('id')->first();
    }

    /** Préférences fusionnées avec les valeurs par défaut. */
    public static function preferences(User $user): array
    {
        return array_merge(self::PREFERENCE_DEFAULTS, $user->preferences ?? []);
    }
}
