<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * La racine renvoie un invité vers la connexion (via le tableau de bord protégé).
     */
    public function test_the_root_redirects_guests_to_login(): void
    {
        $this->get('/')->assertRedirect();
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
