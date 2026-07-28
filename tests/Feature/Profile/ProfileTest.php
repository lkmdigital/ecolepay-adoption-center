<?php

namespace Tests\Feature\Profile;

use App\Domains\Users\Models\User;
use App\Domains\Users\Models\UserFavorite;
use App\Domains\Users\Support\CurrentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_resolves_a_current_account_when_none_exists(): void
    {
        $this->assertSame(0, User::count());

        $user = CurrentUser::resolve();

        $this->assertSame(1, User::count());
        $this->assertSame('Utilisateur EAC', $user->name);
        // Le compte de référence n'a pas de mot de passe tant que l'auth n'existe pas.
        $this->assertNull($user->password);
    }

    #[Test]
    public function every_tab_renders(): void
    {
        $component = Livewire::test('profile::index');

        foreach (['profil', 'securite', 'sessions', 'preferences', 'notifications', 'activite', 'favoris', 'confidentialite'] as $tab) {
            $component->set('tab', $tab)->assertOk();
        }
    }

    #[Test]
    public function it_saves_identity_fields_and_splits_the_name(): void
    {
        Livewire::test('profile::index')
            ->set('form.prenom', 'Awa')
            ->set('form.nom', 'Koné')
            ->set('form.email', 'awa.kone@lkm.ci')
            ->set('form.job_title', 'Analyste')
            ->call('save')
            ->assertHasNoErrors();

        $user = CurrentUser::resolve();
        $this->assertSame('Awa Koné', $user->name);
        $this->assertSame('awa.kone@lkm.ci', $user->email);
        $this->assertSame('Analyste', $user->job_title);
    }

    #[Test]
    public function it_validates_the_email(): void
    {
        Livewire::test('profile::index')
            ->set('form.email', 'pas-un-email')
            ->call('save')
            ->assertHasErrors('form.email');
    }

    #[Test]
    public function it_persists_preferences(): void
    {
        Livewire::test('profile::index')
            ->set('prefs.theme', 'dark')
            ->set('prefs.default_dashboard', 'analytics')
            ->call('savePrefs');

        $prefs = CurrentUser::preferences(CurrentUser::resolve());
        $this->assertSame('dark', $prefs['theme']);
        $this->assertSame('analytics', $prefs['default_dashboard']);
    }

    #[Test]
    public function it_pins_and_removes_a_favorite(): void
    {
        $user = CurrentUser::resolve();
        $fav = UserFavorite::create(['user_id' => $user->id, 'type' => 'report', 'ref_id' => '99', 'label' => 'Rapport X', 'link_route' => 'reports.show']);

        Livewire::test('profile::index')
            ->call('removeFavorite', $fav->id);

        $this->assertSame(0, UserFavorite::count());
    }

    #[Test]
    public function it_changes_the_password_with_the_correct_current_one(): void
    {
        $user = User::factory()->create(['password' => \Hash::make('password')]);

        Livewire::actingAs($user)->test('profile::index')
            ->set('pw.current', 'wrong-one')
            ->set('pw.new', 'nouveaumdp123')
            ->set('pw.confirm', 'nouveaumdp123')
            ->call('changePassword')
            ->assertHasErrors('pw.current');

        Livewire::actingAs($user)->test('profile::index')
            ->set('pw.current', 'password')
            ->set('pw.new', 'nouveaumdp123')
            ->set('pw.confirm', 'nouveaumdp123')
            ->call('changePassword')
            ->assertHasNoErrors();

        $this->assertTrue(\Hash::check('nouveaumdp123', $user->fresh()->password));
    }

    #[Test]
    public function deleting_the_account_deactivates_it_after_a_password_check(): void
    {
        $user = User::factory()->create(['password' => \Hash::make('password')]);
        $user->forceFill(['is_active' => true])->save();

        Livewire::actingAs($user)->test('profile::index')
            ->set('deletePassword', 'wrong')
            ->call('deleteAccount')
            ->assertHasErrors('deletePassword');
        $this->assertTrue($user->fresh()->is_active);

        Livewire::actingAs($user)->test('profile::index')
            ->set('deletePassword', 'password')
            ->call('deleteAccount');

        $this->assertFalse($user->fresh()->is_active);
    }
}
