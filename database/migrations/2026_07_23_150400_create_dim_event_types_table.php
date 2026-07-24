<?php

use App\Support\Database\SchemaSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dim_event_types', function (Blueprint $table) {
            $table->comment("Catalogue des événements d'usage. Définit ce qui compte comme activité.");

            // SMALLINT : le catalogue se densifie à chaque version d'EcolePay,
            // au-delà des 255 valeurs d'un TINYINT.
            $table->smallIncrements('id');

            $table->string('code', 50)->collation(SchemaSupport::binaryCollation());
            $table->string('label_fr', 80);
            $table->string('category', 30)->comment('auth, consultation, transaction, communication, support');

            // Frontière entre « engagé » et « à risque » : recevoir une
            // notification n'est pas un usage, l'ouvrir en est un.
            $table->boolean('counts_as_activity')->default(false);
            $table->boolean('is_value_action')->default(false)->comment('Acte volontaire, par opposition à passif');
            $table->decimal('activity_weight', 4, 2)->default(1);

            $table->boolean('is_active')->default(true)->comment('Type encore émis par EcolePay');

            $table->unique('code');
            $table->index('counts_as_activity');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dim_event_types');
    }
};
