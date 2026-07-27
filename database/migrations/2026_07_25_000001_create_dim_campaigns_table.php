<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campagnes marketing — lignée native EAC (créées ici, pas synchronisées).
 *
 * Le workflow réel : la campagne tourne dans Perfect CX / WhatsApp Business ; on
 * importe la liste de contacts dans EAC pour en mesurer l'impact sur l'adoption.
 * La table stocke donc l'identité de la campagne, sa fenêtre d'attribution et les
 * compteurs d'import ; l'impact est mesuré à la volée contre les parcours parents.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Le schéma d'origine prévoyait des tables campagnes orientées « envoi/livraison »
        // (accusés, ouvertures, clics) qui ne correspondent pas au workflow réel : les
        // campagnes tournent dans Perfect CX, EAC importe la liste pour mesurer. Ces
        // tables étant vides et inutilisées, on les remplace par un schéma pragmatique.
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('fact_campaign_results');
        Schema::dropIfExists('fact_campaign_contacts');
        Schema::dropIfExists('dim_campaigns');
        Schema::enableForeignKeyConstraints();

        Schema::create('dim_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('school_id')->nullable()->index();
            $table->string('owner')->nullable();
            $table->string('channel')->default('whatsapp');
            $table->string('status')->default('completed');
            $table->date('campaign_date')->nullable();
            $table->unsignedInteger('attribution_window_days')->default(30);

            // Compteurs figés au moment de l'import (audit de la liste importée).
            $table->unsignedInteger('contacts_count')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dim_campaigns');
    }
};
