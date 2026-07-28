<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapports générés dans le Centre de Rapports. Le contenu n'est pas figé : un
 * rapport mémorise sa définition (type, période, filtres, indicateurs) et est
 * re-rendu à la volée depuis les données réelles à l'ouverture.
 *
 * La planification (schedule) est stockée mais la diffusion automatique par
 * e-mail nécessite une infrastructure mail + authentification, à venir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->string('period')->default('school_year');
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('subscription_model')->nullable();
            $table->json('indicators')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->json('schedule')->nullable();
            $table->timestamp('last_generated_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
