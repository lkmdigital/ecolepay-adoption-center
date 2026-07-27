<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contacts ciblés d'une campagne — grain : un numéro importé dans une campagne.
 *
 * `phone_hash` (HMAC-SHA256) est la clé d'identité : c'est par lui qu'on rapproche
 * un contact importé d'un parent EcolePay pour mesurer les conversions post-campagne.
 * `parent_id` est résolu à l'import mais l'attribution (compte créé / paiement après
 * la campagne) reste recalculée à la volée depuis les parcours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fact_campaign_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('dim_campaigns')->cascadeOnDelete();
            $table->string('raw_phone')->nullable();
            $table->string('phone_e164')->nullable();
            $table->binary('phone_hash', length: 32, fixed: true)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('full_name')->nullable();
            $table->boolean('is_valid')->default(true);
            // Photo au moment de l'import : le parent avait-il déjà un compte ?
            $table->boolean('had_account_before')->default(false);
            $table->boolean('is_test')->default(false)->index();
            $table->timestamps();

            $table->index(['campaign_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_campaign_contacts');
    }
};
