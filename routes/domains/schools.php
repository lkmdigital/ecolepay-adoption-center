<?php

use Illuminate\Support\Facades\Route;

// Routes du domaine schools.
Route::livewire('/ecoles', 'schools::index')->name('schools.index');
Route::livewire('/ecoles/{school}', 'schools::show')->name('schools.show');
