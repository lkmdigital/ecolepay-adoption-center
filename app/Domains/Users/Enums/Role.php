<?php

namespace App\Domains\Users\Enums;

/**
 * Les rôles de la V1.
 *
 * La matrice rôle → permissions vit ici et nulle part ailleurs : le seeder
 * se contente de la reporter en base.
 */
enum Role: string
{
    case SuperAdmin = 'super-admin';
    case Developer = 'developer';
    case Direction = 'direction';
    case Marketing = 'marketing';
    case Commercial = 'commercial';
    case Support = 'support';
    case Analyst = 'analyst';

    /**
     * Refusé au développeur : tout ce qui reconfigure la plateforme elle-même.
     *
     * @var list<string>
     */
    private const DENIED_TO_DEVELOPER = [
        'roles.manage',
        'settings.update',
    ];

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Developer => 'Développeur',
            self::Direction => 'Direction',
            self::Marketing => 'Marketing',
            self::Commercial => 'Commercial',
            self::Support => 'Support',
            self::Analyst => 'Analyste',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Contrôle total de la plateforme, configuration globale, gestion des utilisateurs et des rôles.',
            self::Developer => 'Accès fonctionnel complet et outils de diagnostic, sans pouvoir reconfigurer les rôles ni les paramètres de production.',
            self::Direction => 'Consultation de tous les tableaux de bord, KPI, rapports et analyses stratégiques.',
            self::Marketing => 'Gestion des campagnes, suivi de leur performance et recommandations IA liées à l\'adoption.',
            self::Commercial => 'Suivi des écoles et des parents, analyse du parcours d\'adoption et actions de conversion.',
            self::Support => 'Assistance aux écoles et aux parents, consultation des informations nécessaires au support.',
            self::Analyst => 'Accès aux données, rapports et exports, sans possibilité de modifier les données métier.',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => Permission::cases(),

            // Défini par soustraction : toute permission ajoutée plus tard
            // revient au développeur sauf si elle rejoint la liste noire.
            self::Developer => array_values(array_filter(
                Permission::cases(),
                fn (Permission $permission): bool => ! in_array($permission->value, self::DENIED_TO_DEVELOPER, true),
            )),

            self::Direction => [
                Permission::DashboardView,
                Permission::SchoolsView,
                Permission::SchoolsExport,
                Permission::ParentsView,
                Permission::ParentsExport,
                Permission::CampaignsView,
                Permission::AnalyticsView,
                Permission::AnalyticsExport,
                Permission::ReportsView,
                Permission::ReportsGenerate,
                Permission::ReportsExport,
                Permission::AiView,
                Permission::AiGenerate,
                Permission::SettingsView,
                Permission::AuditView,
            ],

            self::Marketing => [
                Permission::DashboardView,
                Permission::SchoolsView,
                Permission::SchoolsExport,
                Permission::ParentsView,
                Permission::ParentsExport,
                Permission::CampaignsView,
                Permission::CampaignsCreate,
                Permission::CampaignsUpdate,
                Permission::CampaignsDelete,
                Permission::CampaignsSend,
                Permission::AnalyticsView,
                Permission::AnalyticsExport,
                Permission::ReportsView,
                Permission::ReportsGenerate,
                Permission::ReportsExport,
                Permission::AiView,
                Permission::AiGenerate,
            ],

            // Consulte et convertit : aucun export, aucune écriture.
            self::Commercial => [
                Permission::DashboardView,
                Permission::SchoolsView,
                Permission::ParentsView,
                Permission::CampaignsView,
                Permission::AnalyticsView,
                Permission::ReportsView,
                Permission::AiView,
                Permission::AiGenerate,
            ],

            // Strictement la consultation nécessaire pour résoudre un ticket.
            self::Support => [
                Permission::DashboardView,
                Permission::SchoolsView,
                Permission::ParentsView,
                Permission::CampaignsView,
            ],

            // Lecture et export sur toute la donnée, aucune écriture métier.
            self::Analyst => [
                Permission::DashboardView,
                Permission::SchoolsView,
                Permission::SchoolsExport,
                Permission::ParentsView,
                Permission::ParentsExport,
                Permission::CampaignsView,
                Permission::AnalyticsView,
                Permission::AnalyticsExport,
                Permission::ReportsView,
                Permission::ReportsGenerate,
                Permission::ReportsExport,
                Permission::AiView,
                Permission::AiGenerate,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function permissionValues(): array
    {
        return array_map(fn (Permission $permission): string => $permission->value, $this->permissions());
    }

    public function has(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
