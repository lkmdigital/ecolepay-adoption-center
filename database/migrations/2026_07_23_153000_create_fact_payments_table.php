<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fait transactionnel — grain : une tentative de paiement, aboutie ou non.
 *
 * Porte le fait qui définit la conversion : le passage de « inscrit » à « adoptant »
 * n'a pas d'autre déclencheur que le premier paiement abouti.
 *
 * Les échecs sont conservés délibérément : un premier paiement échoué est le signal
 * le plus actionnable du dispositif — le parent a essayé et n'y est pas parvenu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fact_payments', function (Blueprint $table) {
            $table->comment('Transactions de paiement synchronisées depuis EcolePay.');

            $table->id();

            // Clé source : garantit l'idempotence, une synchronisation rejouée
            // ne crée pas de doublon.
            $table->string('source_payment_id', 64)->comment('Référence EcolePay');

            // dim_dates a une clé INT au format AAAAMMJJ : foreignId() produirait
            // un BIGINT incompatible.
            $table->unsignedInteger('date_id');
            $table->dateTime('paid_at');

            $table->foreignId('parent_id')->constrained('dim_parents')->restrictOnDelete();
            $table->foreignId('school_id')->constrained('dim_schools')->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('dim_students')->restrictOnDelete();

            // dim_payment_methods utilise tinyIncrements.
            $table->unsignedTinyInteger('payment_method_id');

            $table->decimal('amount', 14, 2);
            $table->decimal('fee_amount', 12, 2)->nullable()->comment('Frais réellement prélevés');
            $table->decimal('net_amount', 14, 2)->nullable();
            $table->char('currency', 3)->comment('ISO 4217');

            $table->string('status', 20)->comment('success, failed, pending, refunded, cancelled');
            $table->string('failure_reason', 120)->nullable();

            // Calculés, pas synchronisés. À recalculer sur la fenêtre de reprise :
            // une transaction arrivée en retard peut se révéler antérieure à celle
            // déjà marquée comme première.
            $table->boolean('is_first_payment')->default(false);
            $table->unsignedInteger('payment_sequence')->nullable();
            $table->unsignedSmallInteger('days_since_previous_payment')->nullable();

            $table->string('installment_label', 40)->nullable()->comment('Tranche ou échéance');
            $table->char('school_year_label', 9)->nullable()->comment('Dimension dégénérée, évite une jointure');

            $table->boolean('is_test')->default(false);

            $table->dateTime('source_created_at')->nullable();
            $table->dateTime('synced_at');
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();

            $table->foreign('date_id')->references('id')->on('dim_dates')->restrictOnDelete();
            $table->foreign('payment_method_id')->references('id')->on('dim_payment_methods')->restrictOnDelete();

            $table->unique('source_payment_id');

            $table->index(['school_id', 'date_id']);
            $table->index(['parent_id', 'paid_at']);
            $table->index(['date_id', 'status']);
            $table->index(['is_first_payment', 'date_id']);
            $table->index(['status', 'school_id']);
            $table->index('payment_method_id');
            $table->index('is_test');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_payments');
    }
};
