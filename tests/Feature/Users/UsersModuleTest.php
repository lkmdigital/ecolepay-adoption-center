<?php

namespace Tests\Feature\Users;

use App\Domains\Users\Models\User;
use Database\Seeders\Users\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UsersModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role, string $email): User
    {
        $u = User::create(['name' => ucfirst($role), 'email' => $email, 'password' => Hash::make('password'), 'is_active' => true, 'primary_role_code' => $role]);
        $u->syncRoles([$role]);

        return $u;
    }

    #[Test]
    public function an_admin_can_view_the_module(): void
    {
        Livewire::actingAs($this->user('super-admin', 'admin@ecolepay.ci'))
            ->test('users::index')
            ->assertOk();
    }

    #[Test]
    public function a_role_without_permission_is_forbidden(): void
    {
        // Le support n'a pas la permission users.view.
        $support = $this->user('support', 'support@ecolepay.ci');

        $this->actingAs($support)->get('/utilisateurs')->assertForbidden();
    }

    #[Test]
    public function an_admin_creates_a_user_with_a_role(): void
    {
        Livewire::actingAs($this->user('super-admin', 'admin@ecolepay.ci'))
            ->test('users::index')
            ->call('openCreate')
            ->set('form.name', 'Nouveau Membre')
            ->set('form.email', 'nouveau@ecolepay.ci')
            ->set('form.role', 'analyst')
            ->set('form.password', 'password123')
            ->call('save')
            ->assertHasNoErrors();

        $created = User::where('email', 'nouveau@ecolepay.ci')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('analyst'));
    }

    #[Test]
    public function creating_a_user_requires_a_password(): void
    {
        Livewire::actingAs($this->user('super-admin', 'admin@ecolepay.ci'))
            ->test('users::index')
            ->call('openCreate')
            ->set('form.name', 'Sans Mdp')
            ->set('form.email', 'sansmdp@ecolepay.ci')
            ->set('form.role', 'support')
            ->set('form.password', '')
            ->call('save')
            ->assertHasErrors('form.password');
    }

    #[Test]
    public function deactivating_a_user_blocks_the_account(): void
    {
        $admin = $this->user('super-admin', 'admin@ecolepay.ci');
        $target = $this->user('support', 'cible@ecolepay.ci');

        Livewire::actingAs($admin)->test('users::index')->call('toggleActive', $target->id);

        $this->assertFalse($target->fresh()->is_active);
    }

    #[Test]
    public function an_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->user('super-admin', 'admin@ecolepay.ci');

        Livewire::actingAs($admin)->test('users::index')->call('toggleActive', $admin->id);

        $this->assertTrue($admin->fresh()->is_active);
    }
}
