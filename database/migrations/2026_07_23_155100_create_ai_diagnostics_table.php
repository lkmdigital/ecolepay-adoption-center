<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grain : une analyse générée.
 *
 * `input_snapshot` est la colonne qui donne sa valeur à la table : une
 * recommandation doit rester explicable alors que les données ont changé. Sans les
 * métriques exactes qui l'ont produite, un diagnostic devient une affirmation
 * invérifiable.
 *
 * Discipline de confidentialité : les instantanés stockent des agrégats et des clés
 * de substitution, jamais d'identifiants en clair. La pseudonymisation d'un parent
 * devient ainsi automatiquement effective dans toutes les productions passées, sans
 * balayage ni réécriture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_diagnostics', function (Blueprint $table) {
            $table->comment('Analyses générées, avec de quoi les reproduire.');

            $table->id();
            $table->uuid('uuid');

            $table->string('diagnostic_type', 40)
                ->comment('adoption_analysis, campaign_review, anomaly_detection, school_health');

            // Périmètre polymorphe : école, campagne, région ou segment.
            $table->string('scope_type', 20);
            $table->string('scope_id', 64)->nullable();
            // Figé : une école renommée ne doit pas rendre illisible une analyse
            // vieille d'un an.
            $table->string('scope_label', 180);

            $table->unsignedInteger('period_start_date_id')->nullable();
            $table->unsignedInteger('period_end_date_id')->nullable();

            $table->string('title', 200);
            $table->text('summary')->nullable()->comment('Affiché en liste, conservé après archivage du corps');
            $table->mediumText('body')->nullable();
            $table->json('structured_output')->nullable()->comment('Constats sous forme exploitable');
            $table->decimal('confidence', 4, 3)->nullable();

            // Reproductibilité : les quatre éléments, ou aucun.
            $table->json('input_snapshot');
            $table->dateTime('input_watermark_at');
            $table->string('prompt_version', 20);
            $table->string('model_name', 60);
            $table->json('model_parameters')->nullable();
            $table->foreignId('generation_log_id')->nullable()->constrained('ai_generation_logs')->nullOnDelete();

            $table->string('status', 20)->default('completed')
                ->comment('pending, completed, failed, superseded');
            // Relancer une analyse ne modifie pas l'ancienne : elle est remplacée,
            // jamais écrasée.
            $table->unsignedBigInteger('superseded_by_id')->nullable();

            // Exclut de toute purge : une analyse citée en comité est une archive.
            $table->boolean('is_pinned')->default(false);
            $table->dateTime('published_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->dateTime('last_viewed_at')->nullable();

            // Archivage : corps et instantané déplacés vers le stockage objet à
            // 12 mois, métadonnées conservées.
            $table->string('archived_body_path', 255)->nullable();
            $table->dateTime('archived_at')->nullable();

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('period_start_date_id')->references('id')->on('dim_dates')->restrictOnDelete();
            $table->foreign('period_end_date_id')->references('id')->on('dim_dates')->restrictOnDelete();
            $table->foreign('superseded_by_id')->references('id')->on('ai_diagnostics')->nullOnDelete();

            $table->unique('uuid');

            $table->index(['scope_type', 'scope_id', 'created_at'], 'ai_diagnostics_scope_index');
            $table->index(['diagnostic_type', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('is_pinned');
            $table->index('published_at');
            $table->index('archived_at');
            $table->index('requested_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_diagnostics');
    }
};
