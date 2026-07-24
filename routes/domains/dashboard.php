<?php

use Illuminate\Support\Facades\Route;

// Routes du domaine dashboard.
Route::livewire('/dashboard', 'dashboard::index')->name('dashboard.index');
