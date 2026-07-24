<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indicateur de paiement manuel, repris de `payer.is_manuel`.
 *
 * `is_manuel = 1` = espèces / chèque / virement saisi par l'école via son module de
 * comptabilité interne : le parent n'a PAS utilisé EcolePay. On stocke la ligne (le
 * ratio d'espèces d'une école est un signal de non-adoption), mais elle est exclue
 * de tous les calculs d'adoption, de taux et de revenus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fact_payments', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('failure_reason')
                ->comment('Repris de payer.is_manuel. Exclu de tous les calculs.');

            // Les calculs d'adoption filtrent systématiquement sur (is_manual = 0,
            // statut réussi) : cet index sert ce filtre récurrent.
            $table->index(['is_manual', 'school_id'], 'fact_payments_manual_school_index');
        });
    }

    public function down(): void
    {
        Schema::table('fact_payments', function (Blueprint $table) {
            $table->dropIndex('fact_payments_manual_school_index');
            $table->dropColumn('is_manual');
        });
    }
};
