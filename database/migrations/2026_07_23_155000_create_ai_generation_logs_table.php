<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal de chaque invocation du modèle.
 *
 * Distincte d'`ai_diagnostics` : les appels en échec et les reprises n'ont produit
 * aucune analyse mais ont produit un coût. Les journaliser à part garde la table
 * métier propre tout en rendant la facture lisible.
 *
 * Journalise également la nature des données transmises — un prompt adressé à un
 * modèle externe est une transmission à un tiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generation_logs', function (Blueprint $table) {
            $table->comment('Appels au modèle : coût, latence, échecs.');

            $table->id();

            $table->string('purpose', 40)->comment('diagnostic, recommendation, report_narrative, prediction');
            $table->string('model_name', 60);
            $table->string('prompt_version', 20)->nullable();

            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost', 10, 6)->nullable()->comment('Précision fine : coût unitaire faible');
            $table->char('currency', 3)->nullable();

            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('status', 20)->comment('completed, failed, timeout, rate_limited');
            $table->unsignedTinyInteger('attempt_count')->default(1);
            $table->text('error_message')->nullable();

            $table->string('data_classification', 30)->nullable()
                ->comment('Nature des données transmises : aggregate_only, includes_identifiers');

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('called_at');

            $table->index(['called_at', 'status']);
            $table->index(['model_name', 'called_at']);
            $table->index(['purpose', 'called_at']);
            $table->index('requested_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generation_logs');
    }
};
