<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrégat — grain : une campagne × une période.
 *
 * Piège principal de cette table : le taux de conversion se calcule sur
 * `evaluated_count`, jamais sur `sent_count`. Pendant la fenêtre d'attribution,
 * aucune conversion n'est encore attribuée — diviser par les envois ferait
 * apparaître toute campagne récente comme un échec total, et inciterait à couper
 * une campagne qui fonctionne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agg_campaign_kpis', function (Blueprint $table) {
            $table->comment('Performance et rentabilité des campagnes.');

            $table->id();

            $table->foreignId('campaign_id')->constrained('dim_campaigns')->cascadeOnDelete();
            $table->string('period_type', 10)->default('total')->comment('total, day');
            $table->unsignedInteger('period_start_date_id')->nullable();

            // Remise
            $table->unsignedInteger('targeted_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);

            // Attribution — `evaluated_count` est le dénominateur du taux de
            // conversion, `pending_evaluation_count` doit être affiché pour
            // expliquer qu'un chiffre est encore provisoire.
            $table->unsignedInteger('evaluated_count')->default(0);
            $table->unsignedInteger('pending_evaluation_count')->default(0);
            $table->unsignedInteger('attributed_conversion_count')->default(0);
            $table->decimal('attributed_amount', 16, 2)->default(0);
            $table->unsignedInteger('reactivation_count')->default(0);
            $table->unsignedInteger('sum_days_to_conversion')->default(0);

            // Sommé depuis les coûts figés à l'envoi, jamais recalculé au tarif
            // courant du canal.
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->char('currency', 3)->nullable();

            $table->unsignedTinyInteger('channel_id')->nullable();
            $table->string('objective', 40)->nullable();
            $table->unsignedTinyInteger('target_stage_id')->nullable();
            $table->string('attribution_model', 30)->nullable();

            $table->dateTime('computed_at');
            $table->dateTime('source_watermark_at')->nullable();
            $table->string('computation_version', 20)->nullable();
            $table->boolean('is_final')->default(false)->comment('Toutes les fenêtres closes');

            $table->foreign('period_start_date_id')->references('id')->on('dim_dates')->restrictOnDelete();
            $table->foreign('channel_id')->references('id')->on('dim_channels')->nullOnDelete();
            $table->foreign('target_stage_id')->references('id')->on('dim_adoption_stages')->nullOnDelete();

            $table->unique(['campaign_id', 'period_type', 'period_start_date_id'], 'agg_campaign_kpis_grain_unique');

            $table->index(['period_type', 'period_start_date_id'], 'agg_campaign_kpis_period_index');
            $table->index(['channel_id', 'period_type']);
            $table->index('objective');
            $table->index('is_final');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agg_campaign_kpis');
    }
};
