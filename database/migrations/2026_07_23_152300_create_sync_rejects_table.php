<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lignes refusées lors d'une synchronisation, avec leur motif.
 *
 * Une donnée invalide doit être visible : un rejet non tracé se manifeste plus tard
 * sous forme d'un taux d'adoption inexplicablement bas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_rejects', function (Blueprint $table) {
            $table->comment('Lignes rejetées lors de la synchronisation.');

            $table->id();

            // Cascade pertinente : purger une exécution ancienne emporte ses rejets,
            // qui n'ont aucun sens détachés de leur contexte d'exécution.
            $table->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();

            $table->string('entity', 60);
            $table->string('source_identifier', 64)->nullable()->comment('Clé de la ligne refusée, si connue');
            $table->string('reason_code', 40)->comment('missing_parent, invalid_amount, unknown_school…');
            $table->string('reason_detail', 255)->nullable();
            $table->json('payload')->nullable()->comment('Ligne source, pour rejeu après correction');

            $table->boolean('is_resolved')->default(false);
            $table->dateTime('resolved_at')->nullable();

            $table->dateTime('rejected_at');

            $table->index(['entity', 'rejected_at']);
            $table->index(['reason_code', 'is_resolved']);
            $table->index('is_resolved');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_rejects');
    }
};
