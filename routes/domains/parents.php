<?php

use Illuminate\Support\Facades\Route;

// Routes du domaine parents.
Route::livewire('/parents', 'parents::index')->name('parents.index');
Route::livewire('/parents/{parent}', 'parents::show')->name('parents.show');
