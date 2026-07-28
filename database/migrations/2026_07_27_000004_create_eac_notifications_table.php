<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications & alertes du centre de supervision. Les alertes sont détectées
 * automatiquement depuis les données réelles puis upsertées par `signature` (pour
 * ne pas dupliquer et conserver le statut lu/résolu entre deux détections).
 *
 * Table nommée `eac_notifications` pour ne pas entrer en conflit avec le système
 * de notifications natif de Laravel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eac_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('signature')->unique();
            $table->string('kind')->default('alerte');       // notification | alerte | information
            $table->string('priority')->default('moyenne');  // critique | haute | moyenne | faible
            $table->string('module')->default('adoption');   // ecoles | campagnes | parents | rapports | revenus | adoption | sync
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('impact')->nullable();
            $table->string('action')->nullable();
            $table->string('link_route')->nullable();
            $table->unsignedBigInteger('link_param')->nullable();
            $table->string('status')->default('unread');     // unread | in_progress | resolved
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eac_notifications');
    }
};
