<?php

use Illuminate\Support\Facades\Route;

// Routes du domaine reports.
Route::livewire('/rapports', 'reports::index')->name('reports.index');
Route::livewire('/rapports/{report}', 'reports::show')->name('reports.show');
