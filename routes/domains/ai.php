<?php

use Illuminate\Support\Facades\Route;

// Routes du domaine ai (Assistant IA).
Route::livewire('/assistant', 'ai::index')->name('assistant.index');
