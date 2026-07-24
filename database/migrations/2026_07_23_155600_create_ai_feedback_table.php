<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jugement humain sur une production IA.
 *
 * Seule table du dispositif où la cascade est pertinente : un avis portant sur un
 * diagnostic supprimé n'a plus aucun sens, et sa conservation isolée ne servirait
 * qu'à fausser les statistiques de satisfaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->comment('Retours utilisateurs sur les diagnostics et recommandations.');

            $table->id();

            // Cascade assumée : le retour suit le sort de l'élément jugé.
            $table->foreignId('diagnostic_id')->nullable()->constrained('ai_diagnostics')->cascadeOnDelete();
            $table->foreignId('recommendation_id')->nullable()->constrained('ai_recommendations')->cascadeOnDelete();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->boolean('is_useful');
            $table->unsignedTinyInteger('rating')->nullable()->comment('1 à 5');
            $table->string('reason_code', 40)->nullable()
                ->comment('inaccurate, obvious, not_actionable, helpful, insightful');
            $table->text('comment')->nullable();

            $table->timestamps();

            // Un utilisateur ne juge qu'une fois le même élément.
            $table->unique(['diagnostic_id', 'user_id'], 'ai_feedback_diagnostic_user_unique');
            $table->unique(['recommendation_id', 'user_id'], 'ai_feedback_recommendation_user_unique');

            $table->index(['is_useful', 'created_at']);
            $table->index('reason_code');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedback');
    }
};
