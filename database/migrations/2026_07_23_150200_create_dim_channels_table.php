<?php

use App\Support\Database\SchemaSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dim_channels', function (Blueprint $table) {
            $table->comment('Canaux de communication des campagnes. Donnée de référence.');

            $table->tinyIncrements('id');

            $table->string('code', 20)->collation(SchemaSupport::binaryCollation());
            $table->string('label_fr', 40);
            $table->string('provider', 60)->nullable();

            // Valeur indicative uniquement : le coût réellement facturé est figé
            // sur chaque ligne de fact_campaign_deliveries au moment de l'envoi,
            // sinon un changement de tarif falsifierait d'anciennes campagnes.
            $table->decimal('default_unit_cost', 10, 4)->nullable();
            $table->char('currency', 3)->nullable()->comment('ISO 4217');

            $table->unsignedSmallInteger('max_message_length')->nullable();
            $table->boolean('supports_rich_content')->default(false);
            $table->boolean('requires_opt_in')->default(false)->comment('Consentement préalable obligatoire');
            $table->boolean('is_active')->default(true);

            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dim_channels');
    }
};
