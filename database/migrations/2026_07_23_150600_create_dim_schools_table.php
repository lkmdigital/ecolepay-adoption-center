<?php

use App\Support\Database\SchemaSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dim_schools', function (Blueprint $table) {
            $table->comment('Dimension école, historisée en type 2. Synchronisée depuis EcolePay.');

            $table->id();

            $table->string('source_school_id', 64)->comment('Identifiant EcolePay');
            $table->string('school_code', 32)->collation(SchemaSupport::binaryCollation())->nullable();

            $table->string('name', 180);
            $table->string('legal_name', 180)->nullable();
            $table->string('school_type', 30)->comment('public, prive, confessionnel, international');

            // Trois indicateurs plutôt qu'une liste : une école peut cumuler
            // plusieurs niveaux, et des booléens s'indexent et se filtrent.
            $table->boolean('has_preschool')->default(false);
            $table->boolean('has_primary')->default(false);
            $table->boolean('has_secondary')->default(false);

            $table->char('country_code', 2)->comment('ISO 3166-1');
            $table->string('region', 80)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('district', 80)->nullable()->comment('Commune ou quartier');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->unsignedInteger('student_count')->nullable();
            $table->string('size_band', 20)->nullable()->comment('Tranche dérivée, pour regrouper');

            $table->date('onboarded_at')->nullable()->comment('Mise en service EcolePay');
            $table->string('contract_tier', 40)->nullable();
            $table->string('status', 20)->default('active')->comment('prospect, active, suspendue, partie');

            // Permettra de restreindre le rôle Commercial à son portefeuille.
            $table->foreignId('account_manager_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_test')->default(false);

            // Historisation de type 2.
            $table->boolean('is_current')->nullable()->comment('1 ou NULL : voir unicité ci-dessous');
            $table->dateTime('valid_from');
            $table->dateTime('valid_to')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->binary('row_hash', length: 32, fixed: true)->comment('Empreinte des attributs suivis');
            $table->dateTime('source_created_at')->nullable();
            $table->dateTime('source_updated_at')->nullable();
            $table->dateTime('synced_at');

            $table->unique(['source_school_id', 'valid_from'], 'dim_schools_source_version_unique');
            // MySQL n'applique pas l'unicité aux NULL : garantit une seule
            // ligne courante par école, sans index unique partiel.
            $table->unique(['source_school_id', 'is_current'], 'dim_schools_source_current_unique');

            $table->index(['status', 'region']);
            $table->index(['region', 'city']);
            $table->index('is_test');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dim_schools');
    }
};
