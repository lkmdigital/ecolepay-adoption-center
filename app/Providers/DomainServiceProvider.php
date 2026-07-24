<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    /**
     * Les domaines métier de l'application.
     *
     * Chaque entrée correspond à app/Domains/{Domaine} et à resources/views/{domaine}.
     *
     * @var list<string>
     */
    public const DOMAINS = [
        'Dashboard',
        'Schools',
        'Parents',
        'Campaigns',
        'Analytics',
        'AI',
        'Reports',
        'Users',
        'Settings',
    ];

    public function boot(): void
    {
        $this->registerPolicyResolution();
        $this->registerFactoryResolution();
        $this->registerLayoutComponents();
    }

    /**
     * La découverte automatique de Laravel cherche App\Policies\{Model}Policy.
     * Ici les policies vivent dans leur domaine, à côté de leur modèle :
     * App\Domains\Schools\Models\School -> App\Domains\Schools\Policies\SchoolPolicy
     */
    protected function registerPolicyResolution(): void
    {
        Gate::guessPolicyNamesUsing(function (string $modelClass): array|string {
            if (str_contains($modelClass, '\\Models\\')) {
                return str_replace('\\Models\\', '\\Policies\\', $modelClass).'Policy';
            }

            return [];
        });
    }

    /**
     * Laravel déduit la factory depuis le préfixe App\Models\, absent ici.
     * La correspondance se fait dans les deux sens :
     *
     * App\Domains\Users\Models\User <-> Database\Factories\Users\UserFactory
     */
    protected function registerFactoryResolution(): void
    {
        Factory::guessFactoryNamesUsing(function (string $modelClass): string {
            if (preg_match('/^App\\\\Domains\\\\(.+)\\\\Models\\\\(.+)$/', $modelClass, $matches)) {
                return 'Database\\Factories\\'.$matches[1].'\\'.$matches[2].'Factory';
            }

            // Dimensions de référence partagées entre plusieurs domaines.
            if (preg_match('/^App\\\\Shared\\\\Models\\\\(.+)$/', $modelClass, $matches)) {
                return 'Database\\Factories\\Shared\\'.$matches[1].'Factory';
            }

            return 'Database\\Factories\\'.class_basename($modelClass).'Factory';
        });

        Factory::guessModelNamesUsing(function (Factory $factory): string {
            $namespace = str_replace('Database\\Factories\\', '', get_class($factory));

            if (str_contains($namespace, '\\')) {
                [$group, $factoryName] = explode('\\', $namespace, 2);
                $model = str_ends_with($factoryName, 'Factory')
                    ? substr($factoryName, 0, -7)
                    : $factoryName;

                return $group === 'Shared'
                    ? 'App\\Shared\\Models\\'.$model
                    : 'App\\Domains\\'.$group.'\\Models\\'.$model;
            }

            return 'App\\Models\\'.substr($namespace, 0, -7);
        });
    }

    /**
     * Rend resources/views/layouts disponible en Blade via <x-layouts::app>.
     * Livewire y accède de son côté via le namespace « layouts » (config/livewire.php).
     */
    protected function registerLayoutComponents(): void
    {
        Blade::anonymousComponentPath(resource_path('views/layouts'), 'layouts');
    }
}
