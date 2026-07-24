<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grain : un rapport généré.
 *
 * Périmètre plus large que l'IA : la matrice de permissions distingue
 * `reports.generate` de `ai.generate`, tous les rapports ne sont pas rédigés par un
 * modèle. `is_ai_generated` fait la distinction.
 *
 * Deux horloges distinctes : le fichier se supprime, la trace jamais. La permission
 * `audit.view` repose en partie sur cette table — savoir qui a exporté quelles
 * données doit survivre à la destruction du fichier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_reports', function (Blueprint $table) {
            $table->comment('Rapports produits et diffusés, IA ou non.');

            $table->id();
            $table->uuid('uuid');

            $table->string('report_key', 60)->comment('adoption_monthly, school_review, campaign_report');
            $table->string('title', 200);

            $table->string('scope_type', 20);
            $table->string('scope_id', 64)->nullable();
            $table->string('scope_label', 180);

            $table->unsignedInteger('period_start_date_id')->nullable();
            $table->unsignedInteger('period_end_date_id')->nullable();

            // Permet de régénérer à l'identique : c'est ce qui justifie une
            // rétention de fichier courte.
            $table->json('parameters')->nullable();

            $table->string('format', 10)->comment('pdf, xlsx, csv, html');
            // Jamais de BLOB en base : le fichier va sur disque objet, la base ne
            // porte qu'un chemin.
            $table->string('storage_disk', 30)->nullable();
            $table->string('file_path', 255)->nullable()->comment('NULL une fois le fichier purgé');
            $table->unsignedInteger('file_size_bytes')->nullable();
            $table->binary('file_hash', length: 32, fixed: true)->nullable()->comment('Intégrité et dédoublonnage');
            $table->dateTime('file_deleted_at')->nullable()->comment('Fichier supprimé, enregistrement conservé');

            $table->unsignedInteger('row_count')->nullable();
            // Calculé depuis les colonnes réellement exportées, jamais déclaré par
            // l'utilisateur. Détermine la classe de rétention.
            $table->boolean('includes_personal_data')->default(false);
            $table->dateTime('data_watermark_at')->comment('Fraîcheur des données au tirage');

            $table->boolean('is_ai_generated')->default(false);
            $table->foreignId('ai_diagnostic_id')->nullable()->constrained('ai_diagnostics')->nullOnDelete();

            $table->string('generation_status', 20)->default('queued')
                ->comment('queued, generating, completed, failed');
            $table->unsignedInteger('generation_duration_ms')->nullable();
            $table->string('error_message', 255)->nullable();

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('generated_at')->nullable();

            $table->json('distribution')->nullable()->comment('Destinataires');
            $table->unsignedInteger('download_count')->default(0);
            $table->dateTime('last_downloaded_at')->nullable();

            $table->string('retention_class', 20)->default('standard')
                ->comment('transient, standard, archival');
            $table->dateTime('expires_at')->nullable();

            $table->timestamps();

            $table->foreign('period_start_date_id')->references('id')->on('dim_dates')->restrictOnDelete();
            $table->foreign('period_end_date_id')->references('id')->on('dim_dates')->restrictOnDelete();

            $table->unique('uuid');

            $table->index(['report_key', 'created_at']);
            $table->index(['scope_type', 'scope_id'], 'generated_reports_scope_index');
            $table->index(['requested_by_user_id', 'created_at'], 'generated_reports_author_index');
            $table->index(['retention_class', 'expires_at']);
            // Purgés en priorité : c'est la principale surface d'exposition, un
            // fichier téléchargé échappant à tout contrôle d'accès.
            $table->index(['includes_personal_data', 'expires_at'], 'generated_reports_pii_index');
            $table->index('generation_status');
            $table->index('file_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_reports');
    }
};
