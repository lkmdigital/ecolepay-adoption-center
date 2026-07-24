<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dim_campaigns', function (Blueprint $table) {
            $table->comment('Dimension campagne. Lignée native : créée dans EAC, non synchronisée.');

            $table->id();
            $table->uuid('uuid')->comment('Identifiant public, exposé en URL');

            $table->string('name', 160);
            $table->string('slug', 180);
            $table->string('objective', 40)->comment('activation, reactivation, conversion, information');

            // Clés courtes : ces dimensions utilisent tinyIncrements,
            // foreignId() produirait un BIGINT incompatible.
            $table->unsignedTinyInteger('target_stage_id')->nullable()->comment('État visé');
            $table->unsignedTinyInteger('channel_id');

            $table->json('target_segment')->comment('Définition du filtre de ciblage');
            $table->text('message_template')->nullable();

            $table->string('status', 20)->default('draft')->comment('draft, scheduled, sending, sent, cancelled');
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            // Figé au lancement : un dénominateur recalculé plus tard ferait
            // varier le taux de conversion sans qu'aucun message ne parte.
            $table->unsignedInteger('recipient_count')->nullable();

            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->decimal('actual_cost', 12, 2)->nullable()->comment('Consolidé depuis les faits de remise');
            $table->char('currency', 3)->default('XOF')->comment('ISO 4217');

            // Portée par la campagne, pas par un réglage global : une relance
            // de paiement se juge à quelques jours, une campagne de notoriété
            // à plusieurs semaines.
            $table->unsignedSmallInteger('attribution_window_days')->default(14);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            // Suppression logique : une suppression physique orphelinerait
            // fact_campaign_deliveries et effacerait des coûts engagés.
            $table->softDeletes();

            $table->foreign('channel_id')->references('id')->on('dim_channels')->restrictOnDelete();
            $table->foreign('target_stage_id')->references('id')->on('dim_adoption_stages')->nullOnDelete();

            $table->unique('uuid');
            $table->unique('slug');

            $table->index(['status', 'scheduled_at']);
            $table->index('objective');
            $table->index('channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dim_campaigns');
    }
};
