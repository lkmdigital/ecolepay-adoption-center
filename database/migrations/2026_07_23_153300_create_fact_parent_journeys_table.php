<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instantané cumulatif — grain : un parent × une école.
 *
 * Table de travail des tableaux de bord : la majorité des écrans la lisent seule,
 * sans jointure sur les faits d'événements.
 *
 * Le grain parent × école est le choix structurant de tout le schéma. Un parent
 * ayant des enfants dans deux écoles peut avoir payé dans l'une et pas dans
 * l'autre : au grain « parent » seul, les KPI par école seraient faux.
 *
 * Entièrement dérivée — donc reconstructible à tout moment, et jamais source
 * de vérité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fact_parent_journeys', function (Blueprint $table) {
            $table->comment("Parcours d'adoption consolidé, un par couple parent-école.");

            $table->id();

            $table->foreignId('parent_id')->constrained('dim_parents')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('dim_schools')->restrictOnDelete();

            $table->unsignedTinyInteger('current_stage_id');
            $table->unsignedSmallInteger('rule_version_id')->nullable();

            // Sept références vers dim_dates depuis la même table : dimension à
            // rôles multiples, chaque jalon portant un nom explicite.
            $table->unsignedInteger('known_date_id')->nullable();
            $table->unsignedInteger('registered_date_id')->nullable();
            $table->unsignedInteger('first_payment_date_id')->nullable();
            $table->unsignedInteger('last_activity_date_id')->nullable();
            $table->unsignedInteger('at_risk_date_id')->nullable();
            $table->unsignedInteger('lost_date_id')->nullable();
            $table->unsignedInteger('reactivated_date_id')->nullable();

            $table->dateTime('known_at')->nullable();
            $table->dateTime('registered_at')->nullable();
            $table->dateTime('first_payment_at')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->dateTime('at_risk_at')->nullable();
            $table->dateTime('lost_at')->nullable();
            $table->dateTime('reactivated_at')->nullable();

            // Mesures de délai
            $table->unsignedSmallInteger('days_known_to_registered')->nullable();
            $table->unsignedSmallInteger('days_registered_to_first_payment')->nullable();
            $table->unsignedSmallInteger('days_to_adoption')->nullable()->comment('Délai total, connu → adoptant');
            $table->unsignedSmallInteger('days_since_last_activity')->nullable();
            $table->unsignedSmallInteger('days_in_current_stage')->nullable();

            // Mesures de volume
            $table->unsignedInteger('payment_count')->default(0);
            $table->unsignedInteger('successful_payment_count')->default(0);
            $table->unsignedInteger('failed_payment_count')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('avg_payment_amount', 12, 2)->nullable();
            $table->unsignedInteger('activity_event_count')->default(0);
            $table->unsignedInteger('campaign_contact_count')->default(0)->comment('Pression marketing subie');
            $table->unsignedTinyInteger('reactivation_count')->default(0);

            $table->boolean('is_converted')->default(false);
            $table->boolean('is_active')->default(false);
            $table->boolean('has_ever_paid')->default(false);
            $table->boolean('is_test')->default(false);

            $table->dateTime('first_built_at');
            $table->dateTime('last_recomputed_at');

            $table->foreign('current_stage_id')->references('id')->on('dim_adoption_stages')->restrictOnDelete();
            $table->foreign('rule_version_id')->references('id')->on('dim_adoption_rule_versions')->restrictOnDelete();

            foreach ([
                'known_date_id', 'registered_date_id', 'first_payment_date_id',
                'last_activity_date_id', 'at_risk_date_id', 'lost_date_id',
                'reactivated_date_id',
            ] as $dateColumn) {
                $table->foreign($dateColumn)->references('id')->on('dim_dates')->restrictOnDelete();
            }

            // Matérialise le grain.
            $table->unique(['parent_id', 'school_id']);

            $table->index(['school_id', 'current_stage_id']);
            $table->index('current_stage_id');
            // Détection des parents à risque : requête quotidienne du traitement
            // d'inactivité, et liste de relance du Commercial.
            $table->index('days_since_last_activity');
            $table->index(['is_converted', 'school_id']);
            $table->index('first_payment_date_id');
            $table->index('registered_date_id');
            $table->index('is_test');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_parent_journeys');
    }
};
