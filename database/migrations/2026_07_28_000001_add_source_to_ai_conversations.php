<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue l'origine d'une conversation IA :
 *  - `page`   : le module Assistant IA plein écran ;
 *  - `widget` : le bot flottant contextuel (question rapide sur la page courante).
 *
 * `context` retient la page d'origine d'une conversation widget (libellé lisible).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->string('source')->default('page')->after('user_id');
            $table->string('context')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn(['source', 'context']);
        });
    }
};
