<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrégat — grain : un jour × un périmètre géographique.
 *
 * Chaque périmètre est calculé séparément : la ligne `global` n'est jamais la somme
 * des lignes `region`, les parents multi-écoles y seraient comptés plusieurs fois.
 *
 * Dérivé et reconstructible. Jamais source de vérité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agg_daily_kpis', function (Blueprint $table) {
            $table->comment('Indicateurs quotidiens par périmètre géographique.');

            $table->id();

            $table->unsignedInteger('date_id');
            $table->string('scope_level', 10)->comment('global, country, region');
            $table->string('scope_code', 80)->default('ALL');

            // Effectifs en fin de journée
            $table->unsignedInteger('known_count')->default(0);
            $table->unsignedInteger('registered_count')->default(0);
            $table->unsignedInteger('adopter_count')->default(0);
            $table->unsignedInteger('engaged_count')->default(0);
            $table->unsignedInteger('at_risk_count')->default(0);
            $table->unsignedInteger('lost_count')->default(0);
            $table->unsignedInteger('distinct_parent_count')->default(0)
                ->comment('Calculé sur ce périmètre, jamais sommé depuis un niveau plus fin');

            // Mouvements du jour
            $table->unsignedInteger('new_known')->default(0);
            $table->unsignedInteger('new_registered')->default(0);
            $table->unsignedInteger('new_adopters')->default(0);
            $table->unsignedInteger('new_at_risk')->default(0);
            $table->unsignedInteger('new_lost')->default(0);
            $table->unsignedInteger('reactivations')->default(0);

            // Couples numérateur / dénominateur — le taux se calcule à la lecture.
            $table->unsignedInteger('converted_count')->default(0);
            $table->unsignedInteger('eligible_count')->default(0);
            $table->unsignedInteger('registered_to_adopter_count')->default(0);

            $table->unsignedInteger('payment_count')->default(0);
            $table->unsignedInteger('successful_payment_count')->default(0);
            $table->unsignedInteger('failed_payment_count')->default(0);
            $table->unsignedInteger('first_payment_count')->default(0);
            $table->decimal('payment_amount', 16, 2)->default(0);
            $table->char('currency', 3)->nullable();

            $table->unsignedInteger('active_parent_count')->default(0);
            $table->unsignedBigInteger('activity_event_count')->default(0);

            // Somme et effectif plutôt qu'une moyenne : une moyenne ne se recompose
            // pas sans son dénominateur.
            $table->unsignedBigInteger('sum_days_to_adoption')->default(0);
            $table->unsignedInteger('adoption_delay_sample_count')->default(0);

            $table->unsignedSmallInteger('rule_version_id')->nullable();
            $table->dateTime('computed_at');
            $table->dateTime('source_watermark_at')->nullable();
            $table->string('computation_version', 20)->nullable();
            $table->boolean('is_final')->default(false)->comment('Fenêtre de reprise dépassée');

            $table->foreign('date_id')->references('id')->on('dim_dates')->restrictOnDelete();
            $table->foreign('rule_version_id')->references('id')->on('dim_adoption_rule_versions')->restrictOnDelete();

            $table->unique(['date_id', 'scope_level', 'scope_code'], 'agg_daily_kpis_grain_unique');

            $table->index(['scope_level', 'scope_code', 'date_id'], 'agg_daily_kpis_scope_series_index');
            $table->index('date_id');
            $table->index('is_final');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agg_daily_kpis');
    }
};
