<?php

namespace Tests\Feature\Auth;

use App\Domains\Users\Models\User;
use Database\Seeders\Users\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeUser(array $overrides = []): User
    {
        // is_active n'est pas mass-assignable : on le force explicitement.
        $active = $overrides['is_active'] ?? true;
        unset($overrides['is_active']);

        $user = User::create(array_merge([
            'name' => 'Awa Koné',
            'email' => 'awa@ecolepay.ci',
            'password' => Hash::make('password'),
        ], $overrides));
        $user->forceFill(['is_active' => $active])->save();
        $user->syncRoles(['direction']);

        return $user;
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    #[Test]
    public function an_authenticated_user_is_not_redirected_to_login(): void
    {
        // Le Centre d'aide ne dépend pas de fonctions SQL propres à MySQL,
        // il rend donc proprement sous SQLite : il prouve que l'auth passe.
        $this->actingAs($this->makeUser())->get('/aide')->assertOk();
    }

    #[Test]
    public function valid_credentials_log_the_user_in(): void
    {
        $this->makeUser();

        Livewire::test('auth::login')
            ->set('email', 'awa@ecolepay.ci')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('dashboard.index'));

        $this->assertAuthenticated();
    }

    #[Test]
    public function invalid_credentials_are_rejected(): void
    {
        $this->makeUser();

        Livewire::test('auth::login')
            ->set('email', 'awa@ecolepay.ci')
            ->set('password', 'mauvais')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function a_deactivated_account_cannot_log_in(): void
    {
        $this->makeUser(['is_active' => false]);

        Livewire::test('auth::login')
            ->set('email', 'awa@ecolepay.ci')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function logging_in_records_the_login(): void
    {
        $user = $this->makeUser();
        $this->assertNull($user->last_login_at);

        Livewire::test('auth::login')->set('email', 'awa@ecolepay.ci')->set('password', 'password')->call('login');

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertSame(1, $user->fresh()->login_count);
    }
}
