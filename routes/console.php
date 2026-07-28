<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Planification EAC
|--------------------------------------------------------------------------
|
| Synchronisation quotidienne (nuit) depuis la base EcolePay (lecture seule),
| suivie du recalcul des parcours d'adoption. `withoutOverlapping` évite deux
| synchros concurrentes ; `onOneServer` garantit un seul lancement si plusieurs
| workers partagent le cron. Nécessite le cron système :
|
|   * * * * * cd /chemin/vers/eac && php artisan schedule:run >> /dev/null 2>&1
|
| Les fenêtres de reprise (config/eac.restatement_days) rejouent les N derniers
| jours à chaque passage, donc une fréquence quotidienne suffit.
|
*/
Schedule::command('eac:sync all')
    ->dailyAt('02:30')
    ->withoutOverlapping(120)
    ->onOneServer()
    ->then(function () {
        // Le recalcul des parcours suit toujours la synchro des faits.
        Artisan::call('eac:compute journeys');
    });
