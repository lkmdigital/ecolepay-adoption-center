<?php

use Illuminate\Support\Facades\Route;

// Routes du domaine settings (centre de configuration).
Route::livewire('/parametres', 'settings::index')->name('settings.index');
