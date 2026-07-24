<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrégat — grain : une école × une période.
 *
 * `period_type` permet à une seule table de servir les granularités jour, semaine et
 * mois, plutôt que trois tables quasi identiques. L'unicité inclut donc ce champ.
 *
 * Les colonnes `rank_*` imposent un calcul en deux passages : elles dépendent de
 * toutes les autres écoles et ne peuvent être renseignées qu'après le calcul complet
 * de la période.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agg_school_kpis', function (Blueprint $table) {
            $table->comment('Indicateurs par école et par période.');

            $table->id();

            $table->foreignId('school_id')->constrained('dim_schools')->cascadeOnDelete();
            $table->string('period_type', 10)->comment('day, week, month');
            $table->unsignedInteger('period_start_date_id');
            $table->unsignedInteger('period_end_date_id');

            $table->unsignedInteger('known_count')->default(0);
            $table->unsignedInteger('registered_count')->default(0);
            $table->unsignedInteger('adopter_count')->default(0);
            $table->unsignedInteger('engaged_count')->default(0);
            $table->unsignedInteger('at_risk_count')->default(0);
            $table->unsignedInteger('lost_count')->default(0);
            $table->unsignedInteger('distinct_parent_count')->default(0);
            $table->unsignedInteger('student_count')->nullable();

            $table->unsignedInteger('new_registered')->default(0);
            $table->unsignedInteger('new_adopters')->default(0);
            $table->unsignedInteger('new_at_risk')->default(0);
            $table->unsignedInteger('new_lost')->default(0);
            $table->unsignedInteger('reactivations')->default(0);

            // Couples numérateur / dénominateur
            $table->unsignedInteger('converted_count')->default(0);
            $table->unsignedInteger('eligible_count')->default(0);
            $table->unsignedInteger('payment_success_count')->default(0);
            $table->unsignedInteger('payment_attempt_count')->default(0);

            $table->decimal('payment_amount', 16, 2)->default(0);
            $table->unsignedInteger('first_payment_count')->default(0);
            $table->unsignedInteger('failed_payment_count')->default(0);
            $table->char('currency', 3)->nullable();

            $table->unsignedBigInteger('sum_days_to_adoption')->default(0);
            $table->unsignedInteger('adoption_delay_sample_count')->default(0);

            // Figée à la production : recalculer la valeur de référence ferait
            // changer rétroactivement l'écart affiché.
            $table->decimal('adoption_rate_previous_period', 6, 4)->nullable();

            // Renseignées lors du second passage.
            $table->unsignedInteger('rank_national')->nullable();
            $table->unsignedInteger('rank_regional')->nullable();

            // Dénormalisation de filtrage : reflète l'état de l'école à la clôture
            // de la période, ce qui évite de joindre la dimension historisée.
            $table->foreignId('account_manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('region', 80)->nullable();
            $table->char('country_code', 2)->nullable();

            $table->unsignedSmallInteger('rule_version_id')->nullable();
            $table->dateTime('computed_at');
            $table->dateTime('source_watermark_at')->nullable();
            $table->string('computation_version', 20)->nullable();
            $table->boolean('is_final')->default(false);

            $table->foreign('period_start_date_id')->references('id')->on('dim_dates')->restrictOnDelete();
            $table->foreign('period_end_date_id')->references('id')->on('dim_dates')->restrictOnDelete();
            $table->foreign('rule_version_id')->references('id')->on('dim_adoption_rule_versions')->restrictOnDelete();

            $table->unique(['school_id', 'period_type', 'period_start_date_id'], 'agg_school_kpis_grain_unique');

            $table->index(['period_type', 'period_start_date_id', 'converted_count'], 'agg_school_kpis_ranking_index');
            $table->index(['account_manager_user_id', 'period_type', 'period_start_date_id'], 'agg_school_kpis_portfolio_index');
            $table->index(['region', 'period_type', 'period_start_date_id'], 'agg_school_kpis_region_index');
            $table->index(['school_id', 'period_type']);
            $table->index('is_final');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agg_school_kpis');
    }
};
