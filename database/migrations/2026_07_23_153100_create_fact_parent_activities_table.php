<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fait transactionnel — grain : un événement d'usage.
 *
 * Table indispensable : les états « engagé », « à risque » et « perdu » se déduisent
 * de l'inactivité. Sans elle, `fact_adoption_events` avec le déclencheur
 * `inactivity_rule` n'a aucune source.
 *
 * C'est la table volumineuse du schéma — la seule dont le volume croît avec l'usage
 * et non avec la population. Le partitionnement mensuel sur `date_id` (format
 * AAAAMMJJ, retenu pour cela) sera à mettre en place quand le volume le justifiera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fact_parent_activities', function (Blueprint $table) {
            $table->comment("Événements d'usage EcolePay. Fonde le calcul de l'engagement.");

            $table->id();

            $table->string('source_event_id', 64)->nullable()->comment('Référence EcolePay, si fournie');

            $table->unsignedInteger('date_id');
            $table->dateTime('occurred_at');

            $table->foreignId('parent_id')->constrained('dim_parents')->restrictOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('dim_schools')->restrictOnDelete();

            // dim_event_types utilise smallIncrements.
            $table->unsignedSmallInteger('event_type_id');

            $table->string('platform', 20)->nullable()->comment('android, ios, web');
            $table->string('app_version', 20)->nullable();
            $table->string('session_reference', 64)->nullable();

            $table->boolean('is_test')->default(false);

            $table->dateTime('synced_at');
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();

            $table->foreign('date_id')->references('id')->on('dim_dates')->restrictOnDelete();
            $table->foreign('event_type_id')->references('id')->on('dim_event_types')->restrictOnDelete();

            $table->unique('source_event_id');

            // Index le plus sollicité : calcul de la dernière activité d'un parent.
            $table->index(['parent_id', 'occurred_at']);
            $table->index(['date_id', 'event_type_id']);
            $table->index(['school_id', 'date_id']);
            $table->index('is_test');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_parent_activities');
    }
};
