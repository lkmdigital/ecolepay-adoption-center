<?php

use App\Support\Database\SchemaSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dim_payment_methods', function (Blueprint $table) {
            $table->comment('Moyens de paiement. Donnée de référence.');

            $table->tinyIncrements('id');

            $table->string('code', 30)->collation(SchemaSupport::binaryCollation());
            $table->string('label_fr', 60);
            $table->string('category', 30)->comment('mobile_money, card, bank_transfer, cash');
            $table->string('provider', 60)->nullable();
            $table->char('country_code', 2)->nullable()->comment('ISO 3166-1 : les offres sont propres à chaque pays');

            $table->boolean('is_digital')->default(true);
            $table->boolean('is_instant')->default(true)->comment('Confirmation immédiate');

            // Indicatifs : les frais réellement prélevés appartiennent à fact_payments.
            $table->decimal('default_fee_percentage', 5, 4)->nullable();
            $table->decimal('default_fee_fixed', 10, 2)->nullable();

            $table->boolean('is_active')->default(true);

            $table->unique(['code', 'country_code']);
            $table->index('category');
            $table->index('country_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dim_payment_methods');
    }
};
