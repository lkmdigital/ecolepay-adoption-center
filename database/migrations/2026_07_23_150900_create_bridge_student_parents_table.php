<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table de liaison, pas dimension.
     *
     * Un élève a souvent deux responsables, et ce n'est pas toujours le
     * principal qui paie. Une clé étrangère unique sur dim_students rendrait
     * le second invisible.
     */
    public function up(): void
    {
        Schema::create('bridge_student_parents', function (Blueprint $table) {
            $table->comment('Liaison élève ↔ parent, plusieurs à plusieurs et historisée.');

            $table->foreignId('student_id')->constrained('dim_students')->cascadeOnDelete();
            $table->foreignId('parent_id')->constrained('dim_parents')->cascadeOnDelete();

            $table->string('relationship', 30)->nullable()->comment('pere, mere, tuteur');
            $table->boolean('is_primary_payer')->default(false)->comment('Responsable financier principal');

            $table->date('valid_from');
            $table->date('valid_to')->nullable();

            $table->primary(['student_id', 'parent_id', 'valid_from'], 'bridge_student_parents_primary');

            // Parcours dans l'autre sens : tous les élèves d'un parent.
            $table->index(['parent_id', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_student_parents');
    }
};
