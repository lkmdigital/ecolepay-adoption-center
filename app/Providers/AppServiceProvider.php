<?php

namespace App\Providers;

use App\Domains\Users\Enums\Permission;
use App\Domains\Users\Models\User;
use App\Infrastructure\EcolePay\EcolePaySource;
use App\Infrastructure\EcolePay\ReadOnlyGuard;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Garde-fou matériel : la base EcolePay est en LECTURE SEULE. Dès qu'elle
        // est (re)connectée, on installe un intercepteur qui rejette toute requête
        // d'écriture — EAC ne peut jamais modifier la source, même si ses
        // identifiants MySQL disposent de tous les privilèges. Voir ReadOnlyGuard.
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event): void {
            if ($event->connectionName === EcolePaySource::CONNECTION) {
                ReadOnlyGuard::protect($event->connection);
            }
        });

        // Le tableau de bord Pulse suit la même permission que Telescope, et
        // reste ouvert en local pour ne pas imposer une connexion en dev.
        Gate::define('viewPulse', function (?User $user): bool {
            return $this->app->environment('local')
                || ($user?->can(Permission::DiagnosticsView->value) ?? false);
        });
    }
}
