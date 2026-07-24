<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fait transactionnel — grain : un message, pour un parent, dans une campagne.
 *
 * C'est la seule table de faits réellement mutable après insertion : les accusés de
 * remise du prestataire arrivent des heures, voire des jours plus tard. Conséquence
 * directe : tout agrégat calculé dessus doit être recalculable sur une fenêtre
 * glissante de 30 jours, sinon les taux d'ouverture restent sous-évalués pour
 * toujours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fact_campaign_contacts', function (Blueprint $table) {
            $table->comment('Messages envoyés et leur cycle de remise.');

            $table->id();

            $table->foreignId('campaign_id')->constrained('dim_campaigns')->restrictOnDelete();
            $table->foreignId('parent_id')->constrained('dim_parents')->restrictOnDelete();
            // Nullable : une campagne ciblant un segment plutôt qu'un établissement,
            // ou un parent multi-écoles, ne permet pas d'imputation non arbitraire.
            $table->foreignId('school_id')->nullable()->constrained('dim_schools')->restrictOnDelete();

            $table->unsignedTinyInteger('channel_id');
            $table->unsignedInteger('date_id')->comment("Date d'envoi");
            $table->unsignedTinyInteger('attempt_number')->default(1);

            $table->dateTime('sent_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('clicked_at')->nullable();
            $table->dateTime('failed_at')->nullable();

            $table->string('delivery_status', 20)->default('queued')
                ->comment('queued, sent, delivered, opened, clicked, failed, bounced');
            $table->string('failure_reason', 120)->nullable();
            $table->string('provider_message_id', 120)->nullable()->comment('Rapprochement des accusés');

            // Figé à l'envoi : recalculer au tarif courant falsifierait la
            // rentabilité des anciennes campagnes.
            $table->decimal('actual_cost', 10, 4)->nullable();
            $table->char('currency', 3)->nullable();

            // Indispensable et non reconstituable : sans lui, impossible de juger
            // a posteriori si la campagne visait juste, l'état du parent ayant
            // changé entre-temps — éventuellement grâce à la campagne.
            $table->unsignedTinyInteger('stage_id_at_send')->nullable();

            $table->unsignedSmallInteger('minutes_to_delivery')->nullable();

            $table->boolean('is_test')->default(false);
            $table->timestamps();

            $table->foreign('channel_id')->references('id')->on('dim_channels')->restrictOnDelete();
            $table->foreign('date_id')->references('id')->on('dim_dates')->restrictOnDelete();
            $table->foreign('stage_id_at_send')->references('id')->on('dim_adoption_stages')->restrictOnDelete();

            $table->unique(['campaign_id', 'parent_id', 'attempt_number'], 'fact_campaign_contacts_unique');

            $table->index(['campaign_id', 'delivery_status']);
            $table->index(['parent_id', 'sent_at']);
            $table->index(['date_id', 'channel_id']);
            $table->index('provider_message_id');
            $table->index('stage_id_at_send');
            $table->index('is_test');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_campaign_contacts');
    }
};
