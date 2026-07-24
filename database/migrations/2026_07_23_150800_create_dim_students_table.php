<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dim_students', function (Blueprint $table) {
            $table->comment("Dimension élève : relie le parent à l'école et porte le niveau.");

            $table->id();

            $table->string('source_student_id', 64)->comment('Identifiant EcolePay');
            $table->foreignId('school_id')->constrained('dim_schools')->cascadeOnUpdate()->restrictOnDelete();

            // Aucun nom d'enfant : sans valeur analytique et données de mineurs.
            $table->string('display_reference', 60)->nullable()->comment('Matricule ou libellé technique');

            $table->string('education_level', 40)->nullable()->comment('CP, CE1, 6e, Terminale…');
            // Les nomenclatures ne se trient pas alphabétiquement : « CM2 »
            // précède « 6e », « Terminale » suit « 1re ».
            $table->unsignedTinyInteger('level_rank')->nullable()->comment('Rang ordinal du niveau');
            $table->string('class_label', 40)->nullable()->comment('Ex. 6e B');

            $table->char('school_year_label', 9);
            $table->string('enrollment_status', 20)->default('enrolled')->comment('enrolled, left, graduated');
            $table->date('enrolled_at')->nullable();
            $table->date('left_at')->nullable();

            $table->boolean('is_test')->default(false);

            $table->binary('row_hash', length: 32, fixed: true);
            $table->dateTime('source_created_at')->nullable();
            $table->dateTime('source_updated_at')->nullable();
            $table->dateTime('synced_at');

            // Un élève réinscrit l'année suivante est une nouvelle ligne :
            // sa progression devient suivable.
            $table->unique(['source_student_id', 'school_year_label'], 'dim_students_source_year_unique');

            $table->index(['school_id', 'school_year_label']);
            $table->index('education_level');
            $table->index('level_rank');
            $table->index('enrollment_status');
            $table->index('is_test');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dim_students');
    }
};
