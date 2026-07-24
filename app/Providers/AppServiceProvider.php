<?php

namespace App\Providers;

use App\Domains\Users\Enums\Permission;
use App\Domains\Users\Models\User;
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
        // Le tableau de bord Pulse suit la même permission que Telescope, et
        // reste ouvert en local pour ne pas imposer une connexion en dev.
        Gate::define('viewPulse', function (?User $user): bool {
            return $this->app->environment('local')
                || ($user?->can(Permission::DiagnosticsView->value) ?? false);
        });
    }
}
