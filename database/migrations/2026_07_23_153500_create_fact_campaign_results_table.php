<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instantané cumulatif — grain : un parent × une campagne, évalué après clôture
 * de la fenêtre d'attribution.
 *
 * Séparée de `fact_campaign_contacts` pour quatre raisons : moment d'écriture
 * différent (clôture contre envoi), lignée différente (calculé contre observé),
 * mutabilité différente (recalcul complet contre accusés tardifs), et surtout
 * parce qu'un changement de modèle d'attribution ne doit jamais réécrire des faits
 * de remise réellement observés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fact_campaign_results', function (Blueprint $table) {
            $table->comment("Résultat attribué d'un contact de campagne.");

            $table->id();

            $table->foreignId('campaign_id')->constrained('dim_campaigns')->restrictOnDelete();
            $table->foreignId('parent_id')->constrained('dim_parents')->restrictOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('dim_schools')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('fact_campaign_contacts')->nullOnDelete();

            $table->unsignedSmallInteger('attribution_window_days')->comment('Recopiée de la campagne à l\'évaluation');
            $table->dateTime('window_closed_at');

            $table->unsignedTinyInteger('stage_at_send_id')->nullable();
            $table->unsignedTinyInteger('stage_at_close_id')->nullable();

            $table->boolean('has_progressed')->default(false);
            $table->boolean('converted')->default(false);
            $table->boolean('is_reactivation')->default(false);

            $table->unsignedInteger('conversion_date_id')->nullable();
            $table->unsignedSmallInteger('days_to_conversion')->nullable();

            $table->foreignId('attributed_payment_id')->nullable()->constrained('fact_payments')->nullOnDelete();
            $table->decimal('attributed_amount', 14, 2)->nullable();

            // Sans ces deux colonnes, la somme des conversions attribuées peut
            // dépasser le nombre de conversions réelles — un chiffre faux et
            // flatteur, donc rarement contesté.
            $table->string('attribution_model', 30)->default('last_touch')
                ->comment('last_touch, first_touch, any_touch');
            $table->unsignedTinyInteger('competing_contacts')->default(0)
                ->comment('Autres campagnes dans la fenêtre');

            $table->dateTime('computed_at');
            $table->string('computation_version', 20)->comment('Permet le recalcul sélectif');

            $table->foreign('stage_at_send_id')->references('id')->on('dim_adoption_stages')->restrictOnDelete();
            $table->foreign('stage_at_close_id')->references('id')->on('dim_adoption_stages')->restrictOnDelete();
            $table->foreign('conversion_date_id')->references('id')->on('dim_dates')->restrictOnDelete();

            $table->unique(['campaign_id', 'parent_id'], 'fact_campaign_results_unique');

            $table->index(['campaign_id', 'converted']);
            $table->index('parent_id');
            $table->index('conversion_date_id');
            $table->index('computation_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_campaign_results');
    }
};
