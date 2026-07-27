<?php

use Illuminate\Support\Facades\Route;

// Routes du domaine campaigns.
Route::livewire('/campagnes', 'campaigns::index')->name('campaigns.index');
Route::livewire('/campagnes/{campaign}', 'campaigns::show')->name('campaigns.show');
