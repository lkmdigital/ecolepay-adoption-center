<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fait transactionnel — grain : une transition d'état pour un parent × une école.
 *
 * Registre de la machine à états. C'est cette table qui rend possibles les cohortes,
 * la vélocité d'entonnoir et surtout les réactivations — invisibles si l'on ne
 * regarde que l'état courant.
 *
 * Deux des six états n'existent nulle part dans les données sources : personne
 * n'émet d'événement « ce parent est devenu à risque ». D'où `rule_version_id`,
 * sans lequel une inflexion de courbe ne se distingue pas d'un changement de
 * définition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fact_adoption_events', function (Blueprint $table) {
            $table->comment("Transitions d'état du parcours d'adoption.");

            $table->id();

            $table->unsignedInteger('date_id');
            $table->dateTime('occurred_at');

            $table->foreignId('parent_id')->constrained('dim_parents')->restrictOnDelete();
            $table->foreignId('school_id')->constrained('dim_schools')->restrictOnDelete();

            // dim_adoption_stages utilise tinyIncrements. Dimension à rôles
            // multiples : deux références depuis la même table.
            $table->unsignedTinyInteger('from_stage_id')->nullable()->comment("NULL à l'entrée dans l'entonnoir");
            $table->unsignedTinyInteger('to_stage_id');

            // Une transition produite par un paiement constaté et une transition
            // produite par un franchissement de seuil n'ont pas la même fiabilité.
            $table->string('trigger_type', 30)->comment('payment, registration, inactivity_rule, activity, sync, manual');
            $table->string('trigger_reference', 64)->nullable();

            $table->unsignedSmallInteger('rule_version_id')->nullable();

            $table->unsignedSmallInteger('days_in_previous_stage')->nullable()->comment('Mesure de vélocité');

            $table->boolean('is_progression')->default(false);
            $table->boolean('is_regression')->default(false);
            $table->boolean('is_reactivation')->default(false);

            $table->boolean('is_test')->default(false);
            $table->dateTime('computed_at');

            $table->foreign('date_id')->references('id')->on('dim_dates')->restrictOnDelete();
            $table->foreign('from_stage_id')->references('id')->on('dim_adoption_stages')->restrictOnDelete();
            $table->foreign('to_stage_id')->references('id')->on('dim_adoption_stages')->restrictOnDelete();
            $table->foreign('rule_version_id')->references('id')->on('dim_adoption_rule_versions')->restrictOnDelete();

            // Permet de rejouer un calcul sans dupliquer : la table doit rester
            // reconstructible depuis les paiements et l'activité.
            $table->unique(
                ['parent_id', 'school_id', 'occurred_at', 'to_stage_id'],
                'fact_adoption_events_natural_unique'
            );

            $table->index(['parent_id', 'school_id', 'occurred_at'], 'fact_adoption_events_journey_index');
            $table->index(['to_stage_id', 'date_id']);
            $table->index(['date_id', 'trigger_type']);
            $table->index(['is_reactivation', 'date_id']);
            $table->index('rule_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_adoption_events');
    }
};
