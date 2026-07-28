<?php

namespace Database\Seeders\Users;

use App\Domains\Users\Enums\Role as RoleEnum;
use App\Domains\Users\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Comptes de démonstration, un par rôle, pour ouvrir une session.
 *
 * NON chargé par défaut (hors ReferenceDataSeeder) : ce sont des comptes de
 * démonstration avec un mot de passe commun. En production, un administrateur
 * réel est créé séparément. Idempotent (updateOrCreate par e-mail).
 *
 *     php artisan db:seed --class="Database\Seeders\Users\UsersSeeder"
 */
class UsersSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $accounts = [
            ['Admin EAC', 'admin@ecolepay.ci', RoleEnum::SuperAdmin, 'Administrateur', 'Direction'],
            ['Awa Koné', 'direction@ecolepay.ci', RoleEnum::Direction, 'Directrice adoption', 'Direction'],
            ['Yao Kouassi', 'marketing@ecolepay.ci', RoleEnum::Marketing, "Responsable campagnes", 'Marketing'],
            ['Fatou Diarra', 'commercial@ecolepay.ci', RoleEnum::Commercial, 'Chargée de comptes écoles', 'Commercial'],
            ['Ibrahim Traoré', 'support@ecolepay.ci', RoleEnum::Support, 'Support établissements', 'Support'],
            ['Mariam Bamba', 'analyste@ecolepay.ci', RoleEnum::Analyst, 'Analyste données', 'Data'],
        ];

        foreach ($accounts as [$name, $email, $role, $jobTitle, $department]) {
            $user = User::withTrashed()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make(self::PASSWORD),
                    'job_title' => $jobTitle,
                    'department' => $department,
                    'locale' => 'fr',
                    'timezone' => 'Africa/Abidjan',
                ],
            );
            // Hors mass-assignment (voir #[Fillable] du modèle).
            $user->forceFill(['primary_role_code' => $role->value, 'is_active' => true, 'deleted_at' => null])->save();
            $user->syncRoles([$role->value]);
        }

        // Retire le compte de référence provisoire (sans mot de passe) créé avant
        // l'authentification : il ne peut pas ouvrir de session.
        User::where('email', 'compte@adoption-center.local')->forceDelete();
    }
}
