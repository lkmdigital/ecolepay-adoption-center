<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Métriques d'exactitude consolidées, par modèle et par période.
 *
 * Raison d'être : survivre à la purge d'`ai_predictions`. Ces métriques doivent être
 * calculées AVANT la suppression des partitions de prédictions — l'ordre inverse
 * détruirait l'historique de qualité des modèles sans lever la moindre erreur.
 *
 * Quelques centaines de lignes par an : conservation indéfinie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_performance', function (Blueprint $table) {
            $table->comment('Exactitude des modèles, conservée après purge des prédictions.');

            $table->id();

            $table->string('model_key', 40);
            $table->string('model_version', 20);
            $table->string('period_type', 10)->default('month');
            $table->unsignedInteger('period_start_date_id');

            $table->unsignedInteger('evaluated_count')->comment('Prédictions résolues sur la période');
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('true_positive_count')->default(0);
            $table->unsignedInteger('false_positive_count')->default(0);
            $table->unsignedInteger('true_negative_count')->default(0);
            $table->unsignedInteger('false_negative_count')->default(0);

            $table->decimal('precision_score', 6, 5)->nullable();
            $table->decimal('recall_score', 6, 5)->nullable();
            $table->decimal('f1_score', 6, 5)->nullable();

            $table->json('score_band_distribution')->nullable()->comment('Répartition par tranche');

            $table->dateTime('computed_at');

            $table->foreign('period_start_date_id')->references('id')->on('dim_dates')->restrictOnDelete();

            $table->unique(
                ['model_key', 'model_version', 'period_type', 'period_start_date_id'],
                'ai_model_performance_grain_unique'
            );

            $table->index(['model_key', 'period_start_date_id'], 'ai_model_performance_series_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_performance');
    }
};
