<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users` est une dimension conforme : c'est la table d'authentification, utilisée
 * telle quelle comme dimension d'imputation. On l'étend plutôt que d'en créer une
 * copie qu'il faudrait synchroniser.
 *
 * La suppression logique est le point structurant : sans elle, supprimer un
 * utilisateur effacerait la paternité de ses campagnes et de ses diagnostics IA,
 * ce qui est incompatible avec la permission `audit.view`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('job_title', 80)->nullable();
            $table->string('department', 40)->nullable()->comment('Direction, Marketing, Commercial, Support, Analytics, Technique');

            // Rôle courant dénormalisé : évite la triple jointure Spatie
            // (roles + model_has_roles + permissions) sur chaque requête analytique.
            $table->string('primary_role_code', 30)->nullable();

            $table->string('phone', 20)->nullable();
            $table->char('locale', 5)->nullable();
            $table->string('timezone', 40)->nullable();

            // Un départ se traduit par une désactivation, jamais par une suppression.
            $table->boolean('is_active')->default(true);
            $table->dateTime('deactivated_at')->nullable();

            // EAC mesure l'adoption d'EcolePay : il serait paradoxal de ne pas
            // mesurer si les tableaux de bord produits sont réellement consultés.
            $table->dateTime('last_login_at')->nullable();
            $table->unsignedInteger('login_count')->default(0);

            $table->softDeletes();

            $table->index('is_active');
            $table->index('department');
            $table->index('primary_role_code');
            $table->index('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropIndex(['department']);
            $table->dropIndex(['primary_role_code']);
            $table->dropIndex(['last_login_at']);

            $table->dropColumn([
                'job_title', 'department', 'primary_role_code', 'phone',
                'locale', 'timezone', 'is_active', 'deactivated_at',
                'last_login_at', 'login_count', 'deleted_at',
            ]);
        });
    }
};
