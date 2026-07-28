<?php

namespace App\Domains\Settings\Support;

use App\Domains\Settings\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Accès unique aux réglages de la plateforme. Les valeurs par défaut vivent ici
 * (pas en base) : tout module a un repli même avant le premier enregistrement.
 * Un seul cache global évite de requêter `app_settings` à chaque page.
 *
 * `get()` sert les lectures ; `save()` persiste et invalide le cache.
 */
final class Settings
{
    private const CACHE_KEY = 'eac.settings';

    /**
     * Valeurs par défaut = source de vérité initiale. Celles marquées « verrouillé »
     * dans l'UI ne sont pas éditables (règle d'adoption). Les seuils reprennent
     * config/eac.php et les constantes réellement appliquées dans l'app.
     */
    public const DEFAULTS = [
        // Général
        'platform_name' => 'Adoption Center',
        'platform_org' => 'EcolePay',
        'default_landing' => 'dashboard',
        'timezone' => 'Africa/Abidjan',
        'locale' => 'fr',
        'currency' => 'FCFA',
        'date_format' => 'd/m/Y',

        // Adoption & règles métier
        'engaged_min_payments' => 2,
        'school_year_start_month' => 9,
        'payment_window_end_month' => 1,
        'kpi_green_min' => 50,      // taux d'adoption ≥ → vert
        'kpi_orange_min' => 25,     // ≥ → orange, sinon rouge
        'critical_rate_max' => 15,  // école critique si taux <
        'critical_known_min' => 50, // … et au moins N parents connus
        'health_target' => 60,      // score de santé cible

        // Campagnes
        'campaign_default_channel' => 'sms',
        'attribution_window_days' => 30,

        // Notifications
        'notif_enabled' => true,
        'notif_drop_threshold' => 20,   // % de chute des premiers paiements
        'notif_critical_schools' => true,
        'notif_revenue_milestones' => true,
        'notif_digest' => 'daily',

        // Rapports & exports
        'report_default_period' => 'last_30_days',
        'report_footer' => 'Confidentiel — LKM Digital',
        'export_include_test' => false,

        // Apparence
        'theme' => 'light',
        'density' => 'confortable',

        // Maintenance
        'maintenance_mode' => false,

        // Assistant IA (API Claude)
        'ai_enabled' => true,
        'ai_api_key' => '',            // saisie par l'utilisateur ; sinon ANTHROPIC_API_KEY
        'ai_model' => 'claude-opus-5',
        'ai_effort' => 'low',          // low | medium | high — réactivité du chat
        'ai_max_tokens' => 2048,
    ];

    /** Réglage unitaire, avec repli sur les valeurs par défaut. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return $all[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    /** Tous les réglages fusionnés (défauts + valeurs enregistrées). */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            // Avant la migration (ex. pendant les tests d'install), on sert les défauts.
            if (! Schema::hasTable('app_settings')) {
                return self::DEFAULTS;
            }

            $stored = AppSetting::query()->pluck('value', 'key')
                ->map(fn ($v) => is_array($v) && array_key_exists('v', $v) ? $v['v'] : $v)
                ->all();

            return array_merge(self::DEFAULTS, $stored);
        });
    }

    /** Persiste un lot de réglages et invalide le cache. */
    public static function save(array $values): void
    {
        foreach ($values as $key => $value) {
            AppSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => ['v' => $value]],
            );
        }

        Cache::forget(self::CACHE_KEY);
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
