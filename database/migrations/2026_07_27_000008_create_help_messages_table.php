<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages issus du Centre d'aide :
 *  - `support`  : demande de support / signalement de problème saisie par un
 *    utilisateur (le routage vers un outil de ticketing viendra plus tard) ;
 *  - `feedback` : « cette réponse vous a-t-elle aidé ? » en bas d'un article.
 *
 * On persiste réellement ces messages plutôt que de simuler l'action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_messages', function (Blueprint $table) {
            $table->id();
            $table->string('kind');                 // support | feedback
            $table->string('category')->nullable(); // type de demande / catégorie
            $table->string('article_key')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->boolean('helpful')->nullable();
            $table->string('author')->default('Utilisateur EAC');
            $table->timestamps();

            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_messages');
    }
};
