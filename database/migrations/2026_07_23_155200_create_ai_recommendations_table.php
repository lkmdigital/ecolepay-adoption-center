<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grain : une recommandation.
 *
 * Seule table du dispositif conservée cinq ans sans archivage : le volume est
 * négligeable et c'est le seul indicateur de qualité de la fonctionnalité IA.
 * `linked_campaign_id` la rapproche de `agg_campaign_kpis`, `metric_before` et
 * `metric_after` mesurent son effet réel.
 *
 * `expires_at` est indispensable : une recommandation portant sur une école
 * désormais partie est du bruit, et une liste de conseils obsolètes cesse d'être
 * consultée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->comment('Recommandations actionnables et leur résultat mesuré.');

            $table->id();
            $table->uuid('uuid');

            // Nullable et sans cascade : une recommandation vit cinq ans, un
            // diagnostic est archivé à douze mois. Elle doit lui survivre.
            $table->foreignId('diagnostic_id')->nullable()->constrained('ai_diagnostics')->nullOnDelete();
            // Toutes les recommandations ne viennent pas d'un modèle : les
            // distinguer permet d'évaluer l'apport propre de l'IA.
            $table->string('source', 20)->default('ai')->comment('ai, rule, manual');

            $table->string('scope_type', 20);
            $table->string('scope_id', 64)->nullable();
            $table->string('scope_label', 180);

            $table->string('action_type', 40)
                ->comment('launch_campaign, contact_school, add_payment_method, train_staff, investigate');
            $table->string('title', 200);
            $table->text('rationale');
            $table->unsignedTinyInteger('priority')->default(3)->comment('1 à 5');
            $table->string('effort_estimate', 20)->nullable()->comment('low, medium, high');

            $table->string('expected_metric', 60)->nullable();
            $table->decimal('expected_value', 12, 4)->nullable();

            $table->string('status', 20)->default('new')
                ->comment('new, accepted, rejected, in_progress, done, expired');
            $table->string('rejection_reason', 200)->nullable();

            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->nullable();

            // Boucle de retour : ferme le cycle diagnostic → action → mesure.
            $table->foreignId('linked_campaign_id')->nullable()->constrained('dim_campaigns')->nullOnDelete();
            $table->string('outcome_status', 20)->nullable()
                ->comment('pending, successful, partial, unsuccessful, inconclusive');
            // Figées à la décision et à la mesure, jamais dérivées à la lecture :
            // recalculer la valeur de départ ferait disparaître l'effet mesuré.
            $table->decimal('metric_before', 12, 4)->nullable();
            $table->decimal('metric_after', 12, 4)->nullable();
            $table->dateTime('outcome_measured_at')->nullable();
            $table->text('outcome_notes')->nullable();

            $table->timestamps();

            $table->unique('uuid');

            $table->index(['status', 'priority', 'created_at'], 'ai_recommendations_queue_index');
            $table->index(['scope_type', 'scope_id'], 'ai_recommendations_scope_index');
            $table->index(['assigned_to_user_id', 'status']);
            $table->index(['outcome_status', 'completed_at']);
            $table->index('diagnostic_id');
            $table->index('linked_campaign_id');
            $table->index('expires_at');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_recommendations');
    }
};
