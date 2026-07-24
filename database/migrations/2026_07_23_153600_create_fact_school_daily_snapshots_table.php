<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instantané périodique — grain : une école × un jour.
 *
 * Rend les courbes de tendance instantanées et immuables. Si une donnée EcolePay
 * est corrigée trois mois plus tard, la photo du jour reste le reflet de ce qui
 * était connu ce jour-là — ce qu'on veut pour un rapport déjà présenté en comité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fact_school_daily_snapshots', function (Blueprint $table) {
            $table->comment("Photo quotidienne de l'adoption par école.");

            $table->id();

            $table->foreignId('school_id')->constrained('dim_schools')->restrictOnDelete();
            $table->unsignedInteger('date_id');

            // Effectifs en fin de journée, un par état de l'entonnoir.
            $table->unsignedInteger('known_count')->default(0);
            $table->unsignedInteger('registered_count')->default(0);
            $table->unsignedInteger('adopter_count')->default(0);
            $table->unsignedInteger('engaged_count')->default(0);
            $table->unsignedInteger('at_risk_count')->default(0);
            $table->unsignedInteger('lost_count')->default(0);

            $table->unsignedInteger('active_parent_count')->default(0);
            $table->unsignedInteger('student_count')->nullable();

            // Mouvements du jour
            $table->unsignedInteger('new_registered')->default(0);
            $table->unsignedInteger('new_adopters')->default(0);
            $table->unsignedInteger('new_at_risk')->default(0);
            $table->unsignedInteger('new_lost')->default(0);
            $table->unsignedInteger('reactivations')->default(0);

            // Couple numérateur / dénominateur : un taux n'est pas additif, il ne
            // doit jamais être stocké seul. Le taux national n'est pas la moyenne
            // des taux par école.
            $table->unsignedInteger('converted_count')->default(0);
            $table->unsignedInteger('eligible_count')->default(0);

            $table->unsignedInteger('payment_count')->default(0);
            $table->unsignedInteger('successful_payment_count')->default(0);
            $table->unsignedInteger('failed_payment_count')->default(0);
            $table->decimal('payment_amount', 16, 2)->default(0);
            $table->char('currency', 3)->nullable();

            $table->unsignedSmallInteger('rule_version_id')->nullable();
            $table->dateTime('computed_at');
            $table->dateTime('source_watermark_at')->nullable()->comment('Fraîcheur des données utilisées');

            $table->foreign('date_id')->references('id')->on('dim_dates')->restrictOnDelete();
            $table->foreign('rule_version_id')->references('id')->on('dim_adoption_rule_versions')->restrictOnDelete();

            $table->unique(['school_id', 'date_id']);

            $table->index(['date_id', 'school_id']);
            $table->index('date_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_school_daily_snapshots');
    }
};
