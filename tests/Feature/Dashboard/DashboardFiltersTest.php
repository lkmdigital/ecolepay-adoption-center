<?php

namespace Tests\Feature\Dashboard;

use App\Domains\Dashboard\Actions\ComputeExecutiveDashboard;
use App\Domains\Dashboard\Actions\DashboardFilterOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Couvre la logique de filtrage du Dashboard exécutif (parties testables hors
 * séries mensuelles, qui utilisent du SQL MySQL-only). On vérifie que les
 * options n'exposent que des valeurs réelles, et que les filtres restreignent
 * bien la requête des paiements (revenu).
 */
class DashboardFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMethods();
        $this->seedReferencedDimensions();
    }

    private function seedMethods(): void
    {
        DB::table('dim_payment_methods')->insert([
            ['id' => 1, 'code' => 'wave', 'label_fr' => 'Wave', 'category' => 'mobile_money', 'is_digital' => true, 'is_instant' => true, 'is_active' => true],
            ['id' => 2, 'code' => 'mtn', 'label_fr' => 'MTN Money', 'category' => 'mobile_money', 'is_digital' => true, 'is_instant' => true, 'is_active' => true],
        ]);
    }

    /** Lignes référencées par les FK de fact_payments + 2 écoles filtrables. */
    private function seedReferencedDimensions(): void
    {
        DB::table('dim_dates')->insert([
            'id' => 1, 'full_date' => '2025-01-01', 'day_of_month' => 1, 'day_of_week' => 3, 'day_name' => 'mer',
            'day_of_year' => 1, 'week_of_year' => 1, 'iso_year' => 2025, 'month_number' => 1, 'month_name' => 'jan',
            'quarter' => 1, 'year' => 2025, 'first_day_of_month' => '2025-01-01', 'last_day_of_month' => '2025-01-31',
            'is_weekend' => false, 'is_public_holiday' => false, 'school_year_label' => '2024-2025', 'school_year_start' => 2024,
            'is_school_day' => true, 'is_school_holiday' => false, 'is_enrollment_period' => false, 'is_payment_period' => true,
        ]);
        DB::table('dim_parents')->insert([
            'id' => 1, 'phone_hash' => 'h', 'first_known_at' => now(), 'marketing_consent' => false,
            'is_pseudonymized' => false, 'is_test' => false, 'row_hash' => 'r', 'synced_at' => now(),
        ]);
        $school = fn (int $id, string $name, string $region, string $type) => [
            'id' => $id, 'source_school_id' => 'S'.$id, 'name' => $name, 'school_type' => $type,
            'has_preschool' => false, 'has_primary' => true, 'has_secondary' => false,
            'country_code' => 'CI', 'status' => 'active', 'region' => $region, 'is_test' => false,
            'is_current' => true, 'valid_from' => now(), 'version' => 1, 'row_hash' => 'rh'.$id, 'synced_at' => now(),
        ];
        DB::table('dim_schools')->insert([
            $school(1, 'Ecole A', 'Abidjan', 'prive'),
            $school(2, 'Ecole B', 'Bouake', 'public'),
        ]);
    }

    /** @param array<string, mixed> $o */
    private function payment(array $o = []): array
    {
        return array_merge([
            'source_payment_id' => uniqid('', true),
            'date_id' => 1, 'paid_at' => now(), 'payment_method_id' => 1,
            'parent_id' => 1, 'school_id' => 1, 'amount' => 1000, 'status' => 'success',
            'is_manual' => false, 'is_first_payment' => false, 'is_test' => false,
            'currency' => 'XOF', 'school_year_label' => '2024-2025', 'synced_at' => now(),
        ], $o);
    }

    /** @param array<string, mixed> $filters */
    private function revenue(array $filters): int
    {
        $action = new ComputeExecutiveDashboard;
        $ref = new \ReflectionClass($action);
        $apply = $ref->getMethod('applyFilters');
        $apply->setAccessible(true);
        $apply->invoke($action, $filters);
        $pq = $ref->getMethod('paymentsQuery');
        $pq->setAccessible(true);

        return (int) $pq->invoke($action)->sum('amount');
    }

    #[Test]
    public function options_only_expose_used_operators_and_years(): void
    {
        DB::table('fact_payments')->insert([
            $this->payment(['payment_method_id' => 1, 'school_year_label' => '2024-2025']),
            $this->payment(['payment_method_id' => 1, 'school_year_label' => '2023-2024']),
            // La méthode 2 (MTN) n'est jamais utilisée : elle ne doit PAS apparaître.
        ]);

        $opts = app(DashboardFilterOptions::class)();

        $this->assertSame(['wave' => 'Wave'], $opts['operators']);
        $this->assertSame(['2024-2025' => '2024-2025', '2023-2024' => '2023-2024'], $opts['schoolYears']);
        $this->assertCount(6, $opts['stages']);
        $this->assertSame('Adoptant', $opts['stages'][3]);

        // Écoles / régions / types viennent des 2 écoles seedées.
        $this->assertSame([1 => 'Ecole A', 2 => 'Ecole B'], $opts['schools']);
        $this->assertSame(['Abidjan' => 'Abidjan', 'Bouake' => 'Bouake'], $opts['regions']);
        $this->assertSame(['prive' => 'Privé', 'public' => 'Public'], $opts['schoolTypes']);
    }

    #[Test]
    public function operator_filter_narrows_revenue(): void
    {
        DB::table('fact_payments')->insert([
            $this->payment(['payment_method_id' => 1, 'amount' => 5000]),
            $this->payment(['payment_method_id' => 2, 'amount' => 3000]),
        ]);

        $this->assertSame(8000, $this->revenue([]));
        $this->assertSame(5000, $this->revenue(['operator' => 'wave']));
        $this->assertSame(3000, $this->revenue(['operator' => 'mtn']));
        $this->assertSame(0, $this->revenue(['operator' => 'orange'])); // inexistant => vide
    }

    #[Test]
    public function school_and_year_filters_narrow_revenue(): void
    {
        DB::table('fact_payments')->insert([
            $this->payment(['school_id' => 1, 'school_year_label' => '2024-2025', 'amount' => 1000]),
            $this->payment(['school_id' => 2, 'school_year_label' => '2024-2025', 'amount' => 2000]),
            $this->payment(['school_id' => 1, 'school_year_label' => '2023-2024', 'amount' => 4000]),
        ]);

        $this->assertSame(5000, $this->revenue(['school' => '1']));
        $this->assertSame(2000, $this->revenue(['school' => '2']));
        $this->assertSame(3000, $this->revenue(['schoolYear' => '2024-2025']));
        $this->assertSame(1000, $this->revenue(['school' => '1', 'schoolYear' => '2024-2025']));
    }

    #[Test]
    public function manual_and_pending_payments_are_always_excluded(): void
    {
        DB::table('fact_payments')->insert([
            $this->payment(['amount' => 1000]),
            $this->payment(['amount' => 9000, 'is_manual' => true]),   // manuel exclu
            $this->payment(['amount' => 7000, 'status' => 'pending']), // non réussi exclu
        ]);

        $this->assertSame(1000, $this->revenue([]));
    }
}
