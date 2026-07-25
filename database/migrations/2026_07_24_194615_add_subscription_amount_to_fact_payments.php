<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part d'abonnement d'un paiement, reprise de `payer.abonnement`.
 *
 * Un paiement EcolePay agrège deux flux : les frais scolaires (net_amount, pour
 * l'école) et l'abonnement EcolePay (cette colonne, pour LKM). Les séparer permet
 * de calculer les deux sources de revenus distinctement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fact_payments', function (Blueprint $table) {
            $table->unsignedInteger('subscription_amount')->nullable()->after('net_amount')
                ->comment('Part abonnement EcolePay du paiement (payer.abonnement), en XOF.');
        });
    }

    public function down(): void
    {
        Schema::table('fact_payments', function (Blueprint $table) {
            $table->dropColumn('subscription_amount');
        });
    }
};
