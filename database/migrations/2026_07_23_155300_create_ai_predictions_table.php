<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grain : une cible × un modèle × un cycle de calcul.
 *
 * Table la plus volumineuse du dispositif. À 100 000 parents scorés quotidiennement,
 * elle produirait 36 millions de lignes par an. Deux mesures la ramènent à 3–5
 * millions : n'enregistrer que les variations significatives, et un instantané
 * complet mensuel marqué `is_full_snapshot`.
 *
 * Conserver l'historique est ce qui rend le modèle évaluable : mesurer sa justesse
 * suppose de comparer ce qu'il prédisait avant à ce qui s'est produit après. Une
 * table ne gardant que le score courant ne s'audite pas.
 *
 * Partitionnement mensuel sur `scored_date_id` à mettre en place quand le volume le
 * justifiera — la purge par partition est instantanée, là où un effacement ligne à
 * ligne bloquerait la base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_predictions', function (Blueprint $table) {
            $table->comment('Scores calculés par lot et leur résolution a posteriori.');

            $table->id();

            // Cible polymorphe : parent, école ou campagne.
            $table->string('target_type', 20)->comment('parent, school, campaign');
            $table->unsignedBigInteger('target_id');
            $table->foreignId('school_id')->nullable()->constrained('dim_schools')->nullOnDelete();

            $table->string('model_key', 40)->comment('churn_risk, conversion_propensity, payment_delay');
            $table->string('model_version', 20);

            $table->dateTime('scored_at');
            $table->unsignedInteger('scored_date_id')->comment('Clé de partitionnement future');

            $table->decimal('score', 6, 5)->nullable()->comment('0 à 1');
            $table->string('score_band', 20)->comment('low, medium, high, critical');
            $table->decimal('predicted_value', 12, 2)->nullable()->comment('Ex. jours avant conversion');
            $table->unsignedSmallInteger('horizon_days');

            // Sert la restitution : un score de risque sans ses facteurs
            // explicatifs est inactionnable pour le Commercial.
            $table->json('top_features')->nullable();

            $table->decimal('previous_score', 6, 5)->nullable();
            $table->decimal('score_delta', 6, 5)->nullable();
            $table->boolean('is_full_snapshot')->default(false)->comment('Cycle complet mensuel');

            // 1 ou NULL : MySQL n'applique pas l'unicité aux NULL, ce qui garantit
            // un seul score courant par cible et par modèle sans sous-requête.
            $table->boolean('is_current')->nullable();

            // Résolution à l'échéance de l'horizon.
            $table->string('resolution_status', 20)->default('pending')
                ->comment('pending, resolved, unresolvable');
            $table->dateTime('resolved_at')->nullable();
            $table->boolean('actual_outcome')->nullable();
            $table->decimal('actual_value', 12, 2)->nullable();
            $table->boolean('is_correct')->nullable();

            $table->dateTime('created_at');

            $table->foreign('scored_date_id')->references('id')->on('dim_dates')->restrictOnDelete();

            $table->unique(
                ['target_type', 'target_id', 'model_key', 'is_current'],
                'ai_predictions_current_unique'
            );

            $table->index(['target_type', 'target_id', 'scored_at'], 'ai_predictions_target_index');
            $table->index(['model_key', 'model_version', 'scored_date_id'], 'ai_predictions_model_index');
            $table->index(['score_band', 'is_current']);
            $table->index(['resolution_status', 'resolved_at']);
            $table->index(['scored_date_id', 'is_full_snapshot']);
            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_predictions');
    }
};
