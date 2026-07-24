<?php

namespace Tests\Feature\Users;

use App\Domains\Users\Enums\Permission as PermissionEnum;
use App\Domains\Users\Enums\Role as RoleEnum;
use App\Domains\Users\Models\User;
use Database\Seeders\Users\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function it_creates_every_permission_and_role(): void
    {
        $this->assertSame(count(PermissionEnum::cases()), Permission::count());
        $this->assertSame(count(RoleEnum::cases()), Role::count());
    }

    #[Test]
    #[DataProvider('roles')]
    public function it_grants_each_role_exactly_the_permissions_declared_in_the_enum(string $roleValue): void
    {
        $role = RoleEnum::from($roleValue);

        $granted = Role::findByName($role->value, 'web')
            ->permissions
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $expected = collect($role->permissionValues())->sort()->values()->all();

        $this->assertSame($expected, $granted);
    }

    #[Test]
    public function the_seeder_is_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(count(PermissionEnum::cases()), Permission::count());
        $this->assertSame(count(RoleEnum::cases()), Role::count());
        $this->assertCount(
            count(RoleEnum::SuperAdmin->permissions()),
            Role::findByName(RoleEnum::SuperAdmin->value, 'web')->permissions,
        );
    }

    #[Test]
    public function super_admin_holds_every_permission(): void
    {
        $user = $this->userWith(RoleEnum::SuperAdmin);

        foreach (PermissionEnum::cases() as $permission) {
            $this->assertTrue(
                $user->can($permission->value),
                "Super Admin devrait avoir {$permission->value}",
            );
        }
    }

    #[Test]
    public function only_super_admin_and_developer_manage_users(): void
    {
        $reserved = [
            PermissionEnum::UsersView,
            PermissionEnum::UsersCreate,
            PermissionEnum::UsersUpdate,
            PermissionEnum::UsersDelete,
            PermissionEnum::RolesView,
        ];

        foreach (RoleEnum::cases() as $role) {
            if (in_array($role, [RoleEnum::SuperAdmin, RoleEnum::Developer], true)) {
                continue;
            }

            foreach ($reserved as $permission) {
                $this->assertFalse(
                    $role->has($permission),
                    "{$role->label()} ne devrait pas avoir {$permission->value}",
                );
            }
        }
    }

    #[Test]
    public function only_super_admin_reconfigures_the_platform(): void
    {
        foreach ([PermissionEnum::RolesManage, PermissionEnum::SettingsUpdate] as $permission) {
            foreach (RoleEnum::cases() as $role) {
                if ($role === RoleEnum::SuperAdmin) {
                    continue;
                }

                $this->assertFalse(
                    $role->has($permission),
                    "{$role->label()} ne devrait pas avoir {$permission->value}",
                );
            }
        }
    }

    #[Test]
    public function developer_holds_everything_except_platform_reconfiguration(): void
    {
        $user = $this->userWith(RoleEnum::Developer);

        $this->assertFalse($user->can(PermissionEnum::RolesManage->value));
        $this->assertFalse($user->can(PermissionEnum::SettingsUpdate->value));

        foreach (PermissionEnum::cases() as $permission) {
            if (in_array($permission, [PermissionEnum::RolesManage, PermissionEnum::SettingsUpdate], true)) {
                continue;
            }

            $this->assertTrue(
                $user->can($permission->value),
                "Le développeur devrait avoir {$permission->value}",
            );
        }
    }

    #[Test]
    public function diagnostic_tools_are_reserved_to_super_admin_and_developer(): void
    {
        foreach (RoleEnum::cases() as $role) {
            $expected = in_array($role, [RoleEnum::SuperAdmin, RoleEnum::Developer], true);

            $this->assertSame(
                $expected,
                $role->has(PermissionEnum::DiagnosticsView),
                "diagnostics.view mal attribué à {$role->label()}",
            );
            $this->assertSame(
                $expected,
                $role->has(PermissionEnum::DiagnosticsManage),
                "diagnostics.manage mal attribué à {$role->label()}",
            );
        }
    }

    #[Test]
    public function the_pulse_gate_follows_the_diagnostics_permission(): void
    {
        $developer = $this->userWith(RoleEnum::Developer);
        $analyst = $this->userWith(RoleEnum::Analyst);

        $this->assertTrue(Gate::forUser($developer)->allows('viewPulse'));
        $this->assertFalse(Gate::forUser($analyst)->allows('viewPulse'));
    }

    /**
     * Le gate viewTelescope n'est déclaré qu'en environnement local, Telescope
     * étant une dépendance de développement. On vérifie donc la règle qu'il
     * applique plutôt que le gate lui-même, absent en environnement de test.
     */
    #[Test]
    public function the_telescope_gate_rule_matches_the_diagnostics_permission(): void
    {
        $this->assertTrue($this->userWith(RoleEnum::Developer)->can(PermissionEnum::DiagnosticsView->value));
        $this->assertTrue($this->userWith(RoleEnum::SuperAdmin)->can(PermissionEnum::DiagnosticsView->value));
        $this->assertFalse($this->userWith(RoleEnum::Direction)->can(PermissionEnum::DiagnosticsView->value));
    }

    #[Test]
    public function support_can_only_read(): void
    {
        $user = $this->userWith(RoleEnum::Support);

        $this->assertTrue($user->can(PermissionEnum::SchoolsView->value));
        $this->assertTrue($user->can(PermissionEnum::ParentsView->value));
        $this->assertTrue($user->can(PermissionEnum::CampaignsView->value));

        $this->assertFalse($user->can(PermissionEnum::SchoolsExport->value));
        $this->assertFalse($user->can(PermissionEnum::ParentsExport->value));
        $this->assertFalse($user->can(PermissionEnum::CampaignsSend->value));
        $this->assertFalse($user->can(PermissionEnum::AnalyticsView->value));
        $this->assertFalse($user->can(PermissionEnum::ReportsView->value));
        $this->assertFalse($user->can(PermissionEnum::AiView->value));

        $this->assertCount(4, RoleEnum::Support->permissions());
    }

    #[Test]
    public function commercial_has_no_export_and_no_delete(): void
    {
        $user = $this->userWith(RoleEnum::Commercial);

        foreach ($this->permissionsEndingWith('.export') as $permission) {
            $this->assertFalse(
                $user->can($permission->value),
                "Le commercial ne devrait pas avoir {$permission->value}",
            );
        }

        foreach ($this->permissionsEndingWith('.delete') as $permission) {
            $this->assertFalse($user->can($permission->value));
        }

        $this->assertTrue($user->can(PermissionEnum::AiGenerate->value));
    }

    #[Test]
    public function analyst_cannot_modify_business_data(): void
    {
        $user = $this->userWith(RoleEnum::Analyst);

        foreach (['.create', '.update', '.delete', '.send'] as $suffix) {
            foreach ($this->permissionsEndingWith($suffix) as $permission) {
                $this->assertFalse(
                    $user->can($permission->value),
                    "L'analyste ne devrait pas avoir {$permission->value}",
                );
            }
        }

        $this->assertTrue($user->can(PermissionEnum::AnalyticsExport->value));
        $this->assertTrue($user->can(PermissionEnum::ReportsExport->value));
        $this->assertTrue($user->can(PermissionEnum::ReportsGenerate->value));
    }

    #[Test]
    public function direction_reads_everything_but_writes_nothing(): void
    {
        $user = $this->userWith(RoleEnum::Direction);

        $this->assertTrue($user->can(PermissionEnum::AuditView->value));
        $this->assertTrue($user->can(PermissionEnum::SchoolsExport->value));
        $this->assertTrue($user->can(PermissionEnum::ParentsExport->value));

        foreach (['.create', '.update', '.delete', '.send'] as $suffix) {
            foreach ($this->permissionsEndingWith($suffix) as $permission) {
                $this->assertFalse(
                    $user->can($permission->value),
                    "La direction ne devrait pas avoir {$permission->value}",
                );
            }
        }
    }

    #[Test]
    public function marketing_fully_owns_campaigns(): void
    {
        $user = $this->userWith(RoleEnum::Marketing);

        foreach (PermissionEnum::cases() as $permission) {
            if (str_starts_with($permission->value, 'campaigns.')) {
                $this->assertTrue($user->can($permission->value));
            }
        }

        $this->assertTrue($user->can(PermissionEnum::SchoolsView->value));
        $this->assertTrue($user->can(PermissionEnum::SchoolsExport->value));

        $this->assertFalse($user->can(PermissionEnum::SchoolsUpdate->value));
        $this->assertFalse($user->can(PermissionEnum::AuditView->value));
        $this->assertFalse($user->can(PermissionEnum::SettingsView->value));
    }

    #[Test]
    public function commercial_reads_analytics_and_reports_without_exporting(): void
    {
        $user = $this->userWith(RoleEnum::Commercial);

        $this->assertTrue($user->can(PermissionEnum::AnalyticsView->value));
        $this->assertTrue($user->can(PermissionEnum::ReportsView->value));

        $this->assertFalse($user->can(PermissionEnum::AnalyticsExport->value));
        $this->assertFalse($user->can(PermissionEnum::ReportsGenerate->value));
        $this->assertFalse($user->can(PermissionEnum::ReportsExport->value));
    }

    #[Test]
    public function analyst_reads_and_exports_all_business_data(): void
    {
        $user = $this->userWith(RoleEnum::Analyst);

        foreach ([
            PermissionEnum::SchoolsView,
            PermissionEnum::SchoolsExport,
            PermissionEnum::ParentsView,
            PermissionEnum::ParentsExport,
            PermissionEnum::CampaignsView,
            PermissionEnum::AiGenerate,
        ] as $permission) {
            $this->assertTrue(
                $user->can($permission->value),
                "L'analyste devrait avoir {$permission->value}",
            );
        }
    }

    #[Test]
    public function direction_is_the_only_non_admin_role_seeing_settings(): void
    {
        $this->assertTrue(RoleEnum::Direction->has(PermissionEnum::SettingsView));
        $this->assertTrue(RoleEnum::Direction->has(PermissionEnum::AuditView));

        foreach ([RoleEnum::Marketing, RoleEnum::Commercial, RoleEnum::Support, RoleEnum::Analyst] as $role) {
            $this->assertFalse($role->has(PermissionEnum::SettingsView));
            $this->assertFalse($role->has(PermissionEnum::AuditView));
        }
    }

    #[Test]
    public function data_synced_from_ecolepay_cannot_be_created_or_deleted(): void
    {
        $forbidden = [
            'parents.create', 'parents.update', 'parents.delete',
            'schools.create', 'schools.delete',
        ];

        foreach ($forbidden as $value) {
            $this->assertNotContains(
                $value,
                PermissionEnum::values(),
                "{$value} ne devrait pas exister : la donnée vient d'EcolePay.",
            );
        }
    }

    /**
     * @return list<array{string}>
     */
    public static function roles(): array
    {
        return array_map(
            fn (RoleEnum $role): array => [$role->value],
            RoleEnum::cases(),
        );
    }

    private function userWith(RoleEnum $role): User
    {
        return User::factory()->create()->assignRole($role->value);
    }

    /**
     * @return list<PermissionEnum>
     */
    private function permissionsEndingWith(string $suffix): array
    {
        return array_values(array_filter(
            PermissionEnum::cases(),
            fn (PermissionEnum $permission): bool => str_ends_with($permission->value, $suffix),
        ));
    }
}
