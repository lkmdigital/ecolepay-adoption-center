<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache d'affichage — grain : un écran × un périmètre × une date de référence.
 *
 * De nature différente des agrégats typés : non joignable, jetable, optimisé pour
 * une lecture unique.
 *
 * Deux règles strictes :
 *  - Ne jamais interroger l'intérieur du `payload`. Le jour où l'on en a besoin,
 *    c'est qu'il fallait une table typée.
 *  - Aucun chiffre ne doit exister uniquement ici.
 *
 * Aucune clé étrangère : le périmètre est décrit par un couple volontairement
 * souple, pouvant désigner une école, une campagne ou une région.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_snapshots', function (Blueprint $table) {
            $table->comment('Contenu pré-calculé des écrans. Cache reconstructible.');

            $table->id();

            $table->string('dashboard_key', 60)->comment('main, school_detail, campaign_overview…');
            $table->string('scope_type', 20)->default('global');
            $table->string('scope_id', 64)->default('ALL');
            $table->unsignedInteger('as_of_date_id')->nullable();
            $table->string('period_type', 10)->nullable();

            $table->json('payload')->comment('Tuiles, séries et classements prêts à afficher');
            $table->unsignedInteger('payload_size_bytes')->nullable()->comment('Surveillance de dérive');

            $table->dateTime('computed_at');
            $table->dateTime('expires_at')->nullable();
            // Repris tel quel dans l'interface : « données à jour au… »
            $table->dateTime('source_watermark_at')->nullable();
            $table->string('computation_version', 20)->nullable();

            // Marqué par la fin d'un lot de synchronisation, plutôt que d'attendre
            // l'expiration.
            $table->boolean('is_stale')->default(false);

            $table->foreign('as_of_date_id')->references('id')->on('dim_dates')->nullOnDelete();

            $table->unique(
                ['dashboard_key', 'scope_type', 'scope_id', 'as_of_date_id'],
                'dashboard_snapshots_grain_unique'
            );

            $table->index('expires_at');
            $table->index('is_stale');
            $table->index('computed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_snapshots');
    }
};
