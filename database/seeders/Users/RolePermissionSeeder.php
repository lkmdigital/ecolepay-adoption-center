<?php

namespace Database\Seeders\Users;

use App\Domains\Users\Enums\Permission as PermissionEnum;
use App\Domains\Users\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reporte en base la matrice définie dans les enums du domaine Users.
 *
 * Idempotent : relancez-le après toute modification de Role ou Permission,
 * il crée ce qui manque et resynchronise les rattachements existants.
 */
class RolePermissionSeeder extends Seeder
{
    private const GUARD = 'web';

    public function run(): void
    {
        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, self::GUARD);
        }

        // Indispensable : syncPermissions() résout les noms depuis le cache.
        // Sans ce vidage, un premier passage sur base vierge ne voit pas les
        // permissions tout juste créées et lève PermissionDoesNotExist.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RoleEnum::cases() as $role) {
            Role::findOrCreate($role->value, self::GUARD)
                ->syncPermissions($role->permissionValues());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
