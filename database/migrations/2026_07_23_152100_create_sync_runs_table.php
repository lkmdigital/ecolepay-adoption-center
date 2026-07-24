<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des exécutions de synchronisation.
 *
 * Alimente le « données à jour au… » affiché sur les tableaux de bord — la première
 * question posée devant un chiffre surprenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->comment('Exécutions de synchronisation depuis EcolePay.');

            $table->id();

            $table->string('source', 40)->default('ecolepay');
            $table->string('entity', 60)->comment('payments, parents, schools, activities…');
            $table->string('status', 20)->default('running')->comment('running, completed, failed, partial');

            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_rejected')->default(0);

            // Bornes de la fenêtre traitée : permet de rejouer exactement le lot.
            $table->dateTime('watermark_from')->nullable();
            $table->dateTime('watermark_to')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['entity', 'started_at']);
            $table->index(['status', 'started_at']);
            $table->index(['source', 'entity', 'status'], 'sync_runs_source_entity_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
