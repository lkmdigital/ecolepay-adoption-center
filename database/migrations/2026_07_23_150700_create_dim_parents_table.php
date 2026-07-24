<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dim_parents', function (Blueprint $table) {
            $table->comment('Dimension parent, y compris avant tout compte EcolePay. Type 1.');

            $table->id();

            // Nullable par construction : un « parent connu » est un numéro
            // figurant dans la liste d'une école, sans compte EcolePay.
            // Peupler cette table seulement à l'inscription supprimerait le
            // premier étage de l'entonnoir.
            $table->string('source_parent_id', 64)->nullable()->comment('Identifiant EcolePay, NULL si pas encore inscrit');

            // Empreinte à clé (HMAC + secret applicatif), jamais un hachage nu :
            // un numéro appartient à un espace de moins d'un milliard de valeurs.
            $table->binary('phone_hash', length: 32, fixed: true)->comment('HMAC du numéro normalisé');
            $table->string('phone_e164', 20)->nullable();
            $table->char('phone_country', 2)->nullable();

            $table->string('full_name', 160)->nullable();
            $table->string('email', 180)->nullable();

            $table->char('preferred_language', 5)->nullable()->comment('fr, en');

            // Clé courte : dim_channels utilise tinyIncrements, foreignId()
            // produirait un BIGINT incompatible.
            $table->unsignedTinyInteger('preferred_channel_id')->nullable();

            $table->dateTime('first_known_at')->comment("Première apparition dans une liste d'école");
            $table->dateTime('account_created_at')->nullable()->comment('NULL si jamais inscrit');

            $table->string('last_platform', 20)->nullable();
            $table->string('last_app_version', 20)->nullable();

            $table->boolean('marketing_consent')->default(false);
            $table->dateTime('marketing_consent_at')->nullable();

            // L'effacement se fait par pseudonymisation : les faits référencent
            // cette clé, supprimer la ligne casserait des rapports diffusés.
            $table->boolean('is_pseudonymized')->default(false);
            $table->dateTime('pseudonymized_at')->nullable();
            $table->date('retention_until')->nullable();

            $table->boolean('is_test')->default(false);

            $table->binary('row_hash', length: 32, fixed: true);
            $table->dateTime('source_created_at')->nullable();
            $table->dateTime('source_updated_at')->nullable();
            $table->dateTime('synced_at');

            $table->foreign('preferred_channel_id')
                ->references('id')
                ->on('dim_channels')
                ->nullOnDelete();

            $table->unique('phone_hash');
            // Les NULL multiples sont ici voulus : plusieurs parents connus
            // coexistent sans identifiant EcolePay.
            $table->unique('source_parent_id');

            $table->index('account_created_at');
            $table->index('marketing_consent');
            $table->index('retention_until');
            $table->index('is_test');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dim_parents');
    }
};
