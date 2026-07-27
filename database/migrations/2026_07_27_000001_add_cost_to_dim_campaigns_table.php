<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budget d'une opération marketing, pour mesurer le coût par parent adoptant.
 * Facultatif : renseigné à la création quand il est connu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dim_campaigns', function (Blueprint $table) {
            $table->unsignedBigInteger('cost')->nullable()->after('attribution_window_days');
        });
    }

    public function down(): void
    {
        Schema::table('dim_campaigns', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
    }
};
