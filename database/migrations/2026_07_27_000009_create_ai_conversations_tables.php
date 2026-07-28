<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assistant IA — conversations et messages, rattachés à l'utilisateur courant.
 * Le contenu des messages est du texte ; les réponses proviennent de l'API Claude
 * ancrée sur les données réelles de l'entrepôt (aucune donnée inventée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->default('Nouvelle conversation');
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role'); // user | assistant
            $table->longText('content');
            $table->json('meta')->nullable(); // modèle, tokens, erreur…
            $table->timestamps();
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
