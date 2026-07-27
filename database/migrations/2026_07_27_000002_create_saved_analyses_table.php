<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vues personnalisées du Laboratoire d'Analyses : chaque ligne mémorise une
 * configuration no-code (dimension, mesures, visualisation) pour la rejouer.
 * Rend l'Adoption Center réutilisable sans écrire de SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_analyses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('dimension');
            $table->json('measures');
            $table->string('viz')->default('table');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_analyses');
    }
};
