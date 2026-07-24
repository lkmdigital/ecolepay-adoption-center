<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrégat — grain : un parent, tous établissements confondus.
 *
 * Raison d'être : `fact_parent_journeys` a pour grain « parent × école ». Un parent
 * ayant des enfants dans deux établissements y occupe deux lignes — compter les
 * adoptants au niveau national en les sommant les compterait deux fois.
 * Cette table déduplique.
 *
 * Sert aussi de plan de ciblage : les colonnes dénormalisées évitent une jointure
 * lourde au moment de constituer un segment de campagne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agg_parent_kpis', function (Blueprint $table) {
            $table->comment('Consolidation par personne, tous établissements confondus.');

            $table->id();

            $table->foreignId('parent_id')->constrained('dim_parents')->cascadeOnDelete();

            $table->unsignedTinyInteger('school_count')->default(0);
            $table->foreignId('primary_school_id')->nullable()->constrained('dim_schools')->nullOnDelete();

            // État le plus avancé, avec le moins avancé en garde-fou : un parent
            // « adoptant » à l'école A et « perdu » à l'école B ne doit pas être
            // réduit à une seule lecture.
            $table->unsignedTinyInteger('overall_stage_id');
            $table->unsignedTinyInteger('worst_stage_id')->nullable();

            $table->boolean('is_converted_anywhere')->default(false);
            $table->boolean('is_active_anywhere')->default(false);
            $table->boolean('is_at_risk_everywhere')->default(false)->comment('Signal de départ réel');

            $table->dateTime('first_known_at')->nullable();
            $table->dateTime('first_registered_at')->nullable();
            $table->dateTime('first_payment_at')->nullable();
            $table->dateTime('last_activity_at')->nullable();

            $table->unsignedSmallInteger('days_to_first_adoption')->nullable();
            $table->unsignedSmallInteger('days_since_last_activity')->nullable();

            $table->unsignedInteger('total_payment_count')->default(0);
            $table->unsignedInteger('total_successful_payment_count')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->unsignedInteger('total_activity_event_count')->default(0);
            $table->unsignedInteger('total_campaign_contact_count')->default(0);
            $table->unsignedTinyInteger('reactivation_count')->default(0);

            // Dénormalisation de ciblage
            $table->string('region', 80)->nullable();
            $table->unsignedTinyInteger('preferred_channel_id')->nullable();
            $table->boolean('marketing_consent')->default(false);

            $table->dateTime('computed_at');
            $table->dateTime('source_watermark_at')->nullable();
            $table->string('computation_version', 20)->nullable();

            $table->foreign('overall_stage_id')->references('id')->on('dim_adoption_stages')->restrictOnDelete();
            $table->foreign('worst_stage_id')->references('id')->on('dim_adoption_stages')->restrictOnDelete();
            $table->foreign('preferred_channel_id')->references('id')->on('dim_channels')->nullOnDelete();

            $table->unique('parent_id');

            $table->index('overall_stage_id');
            $table->index('is_converted_anywhere');
            $table->index('days_since_last_activity');
            $table->index(['region', 'overall_stage_id']);
            $table->index(['marketing_consent', 'overall_stage_id'], 'agg_parent_kpis_targeting_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agg_parent_kpis');
    }
};
