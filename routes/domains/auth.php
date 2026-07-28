<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Routes d'authentification. La connexion est accessible aux invités ; la
// déconnexion exige une session ouverte. Ces routes restent HORS du groupe
// protégé par `auth` (voir routes/web.php).
Route::livewire('/login', 'auth::login')->name('login');

Route::post('/logout', function () {
    Auth::guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');
