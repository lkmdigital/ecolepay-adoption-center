<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dernier repère synchronisé avec succès, par entité.
 * Rend la synchronisation incrémentale et reprenable après incident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_watermarks', function (Blueprint $table) {
            $table->comment('Point de reprise de la synchronisation, une ligne par entité.');

            $table->id();

            $table->string('source', 40)->default('ecolepay');
            $table->string('entity', 60);

            $table->dateTime('last_synced_at')->nullable()->comment('Borne haute traitée avec succès');
            $table->string('last_source_id', 64)->nullable()->comment('Pour les sources paginées par identifiant');

            // Nullable : le premier lancement n'a pas d'exécution précédente.
            $table->foreignId('last_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();

            $table->timestamps();

            $table->unique(['source', 'entity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_watermarks');
    }
};
