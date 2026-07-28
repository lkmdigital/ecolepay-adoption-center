<?php

use Illuminate\Support\Facades\Route;

// Routes du domaine users (utilisateurs & rôles).
Route::livewire('/utilisateurs', 'users::index')->name('users.index');
