<?php

use Illuminate\Support\Facades\Route;

// Routes du domaine activity (journal d'activité).
Route::livewire('/journal', 'activity::index')->name('activity.index');
