<?php

use Illuminate\Support\Facades\Route;

// Routes du domaine profile (espace personnel).
Route::livewire('/profil', 'profile::index')->name('profile.index');
