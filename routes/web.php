<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

foreach (glob(__DIR__.'/domains/*.php') as $domainRoutes) {
    require $domainRoutes;
}
