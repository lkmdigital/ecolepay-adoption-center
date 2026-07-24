<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dim_dates', function (Blueprint $table) {
            $table->comment('Dimension calendaire, incluant le référentiel scolaire.');

            // Clé au format AAAAMMJJ : lisible sans jointure, triable, et
            // directement exploitable pour partitionner les tables de faits.
            $table->unsignedInteger('id')->comment('Format AAAAMMJJ');
            $table->primary('id');

            $table->date('full_date');

            $table->unsignedTinyInteger('day_of_month');
            $table->unsignedTinyInteger('day_of_week')->comment('1 = lundi, 7 = dimanche (ISO 8601)');
            $table->string('day_name', 10);
            $table->unsignedSmallInteger('day_of_year');

            $table->unsignedTinyInteger('week_of_year')->comment('Semaine ISO');
            $table->unsignedSmallInteger('iso_year')->comment("Année ISO de la semaine, distincte de l'année civile");

            $table->unsignedTinyInteger('month_number');
            $table->string('month_name', 10);
            $table->unsignedTinyInteger('quarter');
            $table->unsignedSmallInteger('year');

            $table->date('first_day_of_month');
            $table->date('last_day_of_month');

            $table->boolean('is_weekend')->default(false);
            $table->boolean('is_public_holiday')->default(false);
            $table->string('holiday_name', 60)->nullable();

            $table->char('school_year_label', 9)->comment('Ex. 2025-2026');
            $table->unsignedSmallInteger('school_year_start')->comment('Année de rentrée, pour trier');
            $table->unsignedTinyInteger('school_term')->nullable()->comment('Trimestre scolaire 1-3');
            $table->string('school_term_label', 24)->nullable();

            $table->boolean('is_school_day')->default(false);
            $table->boolean('is_school_holiday')->default(false);
            $table->boolean('is_enrollment_period')->default(false)->comment('Période de rentrée');
            $table->boolean('is_payment_period')->default(false)->comment('Échéance de paiement scolaire');

            $table->unique('full_date');
            $table->index(['year', 'month_number']);
            $table->index(['school_year_label', 'school_term']);
            $table->index(['iso_year', 'week_of_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dim_dates');
    }
};
