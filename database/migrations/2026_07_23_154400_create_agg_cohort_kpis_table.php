<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrégat — grain : une cohorte × un nombre de mois écoulés × un périmètre.
 *
 * Répond à la question stratégique qu'aucun autre agrégat ne traite : « nos parents
 * restent-ils ? » Un graphique période sur période peut monter alors que chaque
 * génération se comporte plus mal que la précédente — la croissance du volume masque
 * la dégradation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agg_cohort_kpis', function (Blueprint $table) {
            $table->comment("Rétention et conversion par cohorte d'entrée.");

            $table->id();

            $table->unsignedInteger('cohort_month_date_id')->comment("Premier jour du mois d'entrée");
            $table->string('cohort_basis', 20)->default('registered')
                ->comment('known, registered, first_payment');
            $table->unsignedTinyInteger('months_since')->comment('0, 1, 2…');

            $table->string('scope_level', 10)->default('global')->comment('global, country, region, school');
            $table->string('scope_code', 80)->default('ALL');

            // Figé à la constitution de la cohorte : un dénominateur mouvant
            // rendrait la courbe de rétention ininterprétable.
            $table->unsignedInteger('cohort_size');

            $table->unsignedInteger('still_active_count')->default(0);
            $table->unsignedInteger('converted_count')->default(0)->comment('Cumulé');
            $table->unsignedInteger('at_risk_count')->default(0);
            $table->unsignedInteger('lost_count')->default(0);
            $table->decimal('cumulative_amount', 16, 2)->default(0);

            $table->dateTime('computed_at');
            $table->dateTime('source_watermark_at')->nullable();
            $table->string('computation_version', 20)->nullable();

            $table->foreign('cohort_month_date_id')->references('id')->on('dim_dates')->restrictOnDelete();

            $table->unique(
                ['cohort_month_date_id', 'cohort_basis', 'months_since', 'scope_level', 'scope_code'],
                'agg_cohort_kpis_grain_unique'
            );

            $table->index(['cohort_basis', 'months_since'], 'agg_cohort_kpis_curve_index');
            $table->index(['scope_level', 'scope_code'], 'agg_cohort_kpis_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agg_cohort_kpis');
    }
};
