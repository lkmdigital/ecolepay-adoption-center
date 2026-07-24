<?php

namespace App\Domains\Users\Enums;

/**
 * Les permissions de la plateforme, organisées par module.
 *
 * La valeur de chaque cas est le nom stocké en base par Spatie.
 * Toute permission absente d'ici n'existe pas : le seeder fait foi.
 */
enum Permission: string
{
    // Tableau de bord
    case DashboardView = 'dashboard.view';

    // Écoles — créées côté EcolePay, jamais à la main ici.
    case SchoolsView = 'schools.view';
    case SchoolsUpdate = 'schools.update';
    case SchoolsExport = 'schools.export';

    // Parents — synchronisés depuis EcolePay : lecture et export uniquement.
    case ParentsView = 'parents.view';
    case ParentsExport = 'parents.export';

    // Campagnes
    case CampaignsView = 'campaigns.view';
    case CampaignsCreate = 'campaigns.create';
    case CampaignsUpdate = 'campaigns.update';
    case CampaignsDelete = 'campaigns.delete';
    case CampaignsSend = 'campaigns.send';

    // Analytics
    case AnalyticsView = 'analytics.view';
    case AnalyticsExport = 'analytics.export';

    // Rapports
    case ReportsView = 'reports.view';
    case ReportsGenerate = 'reports.generate';
    case ReportsExport = 'reports.export';

    // IA
    case AiView = 'ai.view';
    case AiGenerate = 'ai.generate';

    // Utilisateurs
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';

    // Rôles & permissions
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';

    // Paramètres
    case SettingsView = 'settings.view';
    case SettingsUpdate = 'settings.update';

    // Journaux d'audit
    case AuditView = 'audit.view';

    // Outils de développement : Telescope, Pulse, données de test.
    case DiagnosticsView = 'diagnostics.view';
    case DiagnosticsManage = 'diagnostics.manage';

    public function module(): Module
    {
        return Module::from(explode('.', $this->value)[0]);
    }

    public function label(): string
    {
        return match ($this) {
            self::DashboardView => 'Consulter le tableau de bord',

            self::SchoolsView => 'Consulter les écoles',
            self::SchoolsUpdate => 'Modifier une école',
            self::SchoolsExport => 'Exporter les écoles',

            self::ParentsView => 'Consulter les parents',
            self::ParentsExport => 'Exporter les parents',

            self::CampaignsView => 'Consulter les campagnes',
            self::CampaignsCreate => 'Créer une campagne',
            self::CampaignsUpdate => 'Modifier une campagne',
            self::CampaignsDelete => 'Supprimer une campagne',
            self::CampaignsSend => 'Lancer une campagne',

            self::AnalyticsView => 'Consulter les analyses',
            self::AnalyticsExport => 'Exporter les analyses',

            self::ReportsView => 'Consulter les rapports',
            self::ReportsGenerate => 'Générer un rapport',
            self::ReportsExport => 'Exporter les rapports',

            self::AiView => 'Consulter les recommandations IA',
            self::AiGenerate => 'Générer un diagnostic IA',

            self::UsersView => 'Consulter les utilisateurs',
            self::UsersCreate => 'Créer un utilisateur',
            self::UsersUpdate => 'Modifier un utilisateur',
            self::UsersDelete => 'Supprimer un utilisateur',

            self::RolesView => 'Consulter les rôles et permissions',
            self::RolesManage => 'Gérer les rôles et permissions',

            self::SettingsView => 'Consulter les paramètres',
            self::SettingsUpdate => 'Modifier les paramètres',

            self::AuditView => 'Consulter les journaux d\'audit',

            self::DiagnosticsView => 'Accéder aux outils de diagnostic',
            self::DiagnosticsManage => 'Gérer les données de test',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
