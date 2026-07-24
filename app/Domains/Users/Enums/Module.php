<?php

namespace App\Domains\Users\Enums;

/**
 * Regroupement des permissions pour l'affichage (écran de gestion des rôles).
 */
enum Module: string
{
    case Dashboard = 'dashboard';
    case Schools = 'schools';
    case Parents = 'parents';
    case Campaigns = 'campaigns';
    case Analytics = 'analytics';
    case Reports = 'reports';
    case AI = 'ai';
    case Users = 'users';
    case Roles = 'roles';
    case Settings = 'settings';
    case Audit = 'audit';
    case Diagnostics = 'diagnostics';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Tableau de bord',
            self::Schools => 'Écoles',
            self::Parents => 'Parents',
            self::Campaigns => 'Campagnes',
            self::Analytics => 'Analytics',
            self::Reports => 'Rapports',
            self::AI => 'IA',
            self::Users => 'Utilisateurs',
            self::Roles => 'Rôles & permissions',
            self::Settings => 'Paramètres',
            self::Audit => 'Journaux d\'audit',
            self::Diagnostics => 'Diagnostic',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => $permission->module() === $this,
        ));
    }
}
