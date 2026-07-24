<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modèle d'abonnement + marquage de la géographie saisie manuellement.
 *
 * Le montant d'abonnement se lit dans `tb_ecole.abonnement` : 0 = l'école prend en
 * charge (intégré à la scolarité), > 0 = montant payé par le parent. On le fige sur
 * la dimension pour le calcul des deux flux de revenus.
 *
 * La géographie (region/city/district) est saisie dans EAC, une fois par école. Le
 * drapeau `geo_locked` indique à la synchronisation de ne PAS l'écraser depuis
 * EcolePay (qui de toute façon ne la fournit pas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dim_schools', function (Blueprint $table) {
            $table->unsignedInteger('subscription_amount')->nullable()->after('contract_tier')
                ->comment('Montant annuel abonnement (XOF). 0 = intégré à la scolarité.');
            $table->string('subscription_model', 20)->nullable()->after('subscription_amount')
                ->comment('bundled = pris en charge par l\'école ; parent_paid = payé par le parent.');
            $table->boolean('geo_locked')->default(false)->after('district')
                ->comment('Géographie saisie manuellement dans EAC : la synchro ne l\'écrase pas.');
        });
    }

    public function down(): void
    {
        Schema::table('dim_schools', function (Blueprint $table) {
            $table->dropColumn(['subscription_amount', 'subscription_model', 'geo_locked']);
        });
    }
};
