<?php

use Illuminate\Support\Facades\Route;

// Routes du domaine analytics.
Route::livewire('/analytics', 'analytics::index')->name('analytics.index');
Route::livewire('/analytics/laboratoire', 'analytics::lab')->name('analytics.lab');
