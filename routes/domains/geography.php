<?php

use Illuminate\Support\Facades\Route;

// Routes du domaine geography (carte de répartition des écoles).
Route::livewire('/carte', 'geography::index')->name('geography.index');
