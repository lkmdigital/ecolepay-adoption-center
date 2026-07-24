<?php

use App\Support\Database\SchemaSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correspondance entre clés naturelles EcolePay et clés de substitution EAC.
 *
 * C'est ce qui absorbe les changements de numéro de téléphone et les fusions de
 * doublons sans casser l'historique des faits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_entity_maps', function (Blueprint $table) {
            $table->comment('Résolution des identifiants source vers les clés internes.');

            $table->id();

            $table->string('entity_type', 40)->collation(SchemaSupport::binaryCollation())
                ->comment('school, parent, student, payment');
            $table->string('source_id', 64)->collation(SchemaSupport::binaryCollation());
            $table->unsignedBigInteger('target_key')->comment('Clé de substitution dans la dimension cible');

            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');

            // Fusion de doublons : l'ancienne clé reste résolvable, redirigée.
            $table->unsignedBigInteger('merged_into_key')->nullable();
            $table->dateTime('merged_at')->nullable();

            $table->timestamps();

            $table->unique(['entity_type', 'source_id'], 'source_entity_maps_natural_unique');
            $table->index(['entity_type', 'target_key']);
            $table->index('merged_into_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_entity_maps');
    }
};
