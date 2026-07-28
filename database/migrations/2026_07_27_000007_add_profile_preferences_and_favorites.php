<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace personnel (module Profil) :
 *  - `users.preferences` : préférences individuelles (langue, thème, densité,
 *    page d'accueil, notifications, briefing IA) — JSON, propre à chaque compte.
 *  - `user_favorites` : éléments épinglés par l'utilisateur (école, rapport,
 *    analyse, campagne, tableau de bord) avec une action rapide.
 *
 * Tant que l'authentification n'est pas posée, ces données se rattachent au
 * « compte courant » résolu par CurrentUser (un seul compte).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'preferences')) {
                $table->json('preferences')->nullable()->after('timezone');
            }
            // Un compte peut exister sans mot de passe tant que l'authentification
            // n'est pas posée (compte courant de référence). Pas de faux credential.
            $table->string('password')->nullable()->change();
        });

        Schema::create('user_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');            // school | report | analysis | campaign | dashboard
            $table->string('ref_id')->nullable();
            $table->string('label');
            $table->string('link_route')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type', 'ref_id']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_favorites');
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'preferences')) {
                $table->dropColumn('preferences');
            }
        });
    }
};
