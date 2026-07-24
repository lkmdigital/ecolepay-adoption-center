<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des actions sensibles réalisées dans EAC.
 *
 * La permission `audit.view` existe dans la matrice de rôles depuis le départ ;
 * cette table est ce qui l'alimente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->comment('Exports, envois de campagne, modifications de rôles et de paramètres.');

            $table->id();

            // Restreint plutôt que cascade : la trace doit survivre à son auteur.
            // La suppression logique sur `users` garantit qu'aucun blocage ne survient.
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->string('action', 60)->comment('parents.export, campaigns.send, roles.manage…');
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 64)->nullable();

            $table->string('scope_type', 20)->nullable();
            $table->string('scope_id', 64)->nullable();

            $table->string('ip_address', 45)->nullable()->comment('IPv4 ou IPv6');
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable()->comment('Volume exporté, filtres appliqués…');

            $table->dateTime('occurred_at');

            $table->index(['user_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
