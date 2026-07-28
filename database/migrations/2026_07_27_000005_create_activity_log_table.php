<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'activité — deux catégories :
 *  - « technique » : actions dans l'application (rapport généré, import…). Les
 *    champs acteur/IP/navigateur restent limités tant que l'authentification
 *    n'est pas en place (acteur = « Système »).
 *  - « métier » : événements de l'activité EcolePay détectés depuis les données
 *    (école qui franchit un palier, campagne qui atteint un cap, seuil de
 *    revenus, école devenue critique, chute des premiers paiements).
 *
 * `signature` (unique nullable) dédoublonne les événements récurrents/backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('signature')->nullable()->unique();
            $table->string('category')->default('technique');  // technique | metier
            $table->string('level')->default('info');           // info | warning | critique
            $table->string('module')->default('systeme');
            $table->string('action')->default('consultation');
            $table->string('actor')->default('Système');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('link_route')->nullable();
            $table->string('result')->default('success');       // success | failure
            $table->json('meta')->nullable();                   // ip, browser, os, session
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamps();

            $table->index(['category', 'level']);
            $table->index('module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
