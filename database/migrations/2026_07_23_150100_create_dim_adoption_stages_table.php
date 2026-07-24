<?php

use App\Support\Database\SchemaSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dim_adoption_stages', function (Blueprint $table) {
            $table->comment("Les six états de l'entonnoir d'adoption. Donnée de référence.");

            // TINYINT : cette clé est recopiée dans chaque ligne de fait, et
            // fact_stage_transitions en portera deux.
            $table->tinyIncrements('id');

            $table->string('code', 20)->collation(SchemaSupport::binaryCollation());
            $table->string('label_fr', 40);
            $table->string('definition', 255);

            $table->unsignedTinyInteger('funnel_rank')->comment('1 à 6, ordre dans l\'entonnoir');

            $table->boolean('is_converted')->comment("Vrai à partir d'« adoptant »");
            $table->boolean('is_active_state')->comment('Le parent utilise encore EcolePay');
            $table->boolean('is_terminal')->comment('Vrai pour « perdu »');
            $table->boolean('is_derived')->comment("Vrai pour « à risque » et « perdu » : déduits d'une règle, non observés");

            $table->char('display_color', 7)->comment('Code hexadécimal');

            $table->unique('code');
            $table->unique('funnel_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dim_adoption_stages');
    }
};
