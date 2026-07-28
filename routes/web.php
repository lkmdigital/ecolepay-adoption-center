<?php

use Illuminate\Support\Facades\Route;

// La racine renvoie vers le tableau de bord (lui-même protégé : un invité y sera
// redirigé vers la connexion).
Route::get('/', fn () => redirect()->route('dashboard.index'));

// Routes d'authentification (connexion invité, déconnexion) — hors du mur d'auth.
require __DIR__.'/domains/auth.php';

// Tous les autres modules exigent une session ouverte.
Route::middleware('auth')->group(function () {
    foreach (glob(__DIR__.'/domains/*.php') as $domainRoutes) {
        if (str_ends_with($domainRoutes, DIRECTORY_SEPARATOR.'auth.php')) {
            continue;
        }
        require $domainRoutes;
    }
});
