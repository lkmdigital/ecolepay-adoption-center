<?php

use Illuminate\Support\Facades\Route;

// Routes du domaine help (centre d'aide / base de connaissances).
Route::livewire('/aide', 'help::index')->name('help.index');
