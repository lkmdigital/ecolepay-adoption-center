<?php

use App\Support\Database\SchemaSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dim_adoption_rule_versions', function (Blueprint $table) {
            $table->comment('Versions de la règle produisant les états « à risque » et « perdu ».');

            $table->smallIncrements('id');

            $table->string('version_label', 20);

            $table->unsignedSmallInteger('at_risk_after_days')->comment('Inactivité déclenchant « à risque »');
            $table->unsignedSmallInteger('lost_after_days')->comment('Inactivité déclenchant « perdu »');
            $table->unsignedTinyInteger('engaged_min_payments')->default(2);

            // Lue par les traitements de calcul, jamais jointe ni filtrée :
            // ne justifie pas une table de liaison.
            $table->json('qualifying_event_types')->comment('Codes de dim_event_types comptant comme activité');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // Nullable et non booléen strict : MySQL n'applique pas l'unicité
            // aux NULL, ce qui garantit une seule version courante.
            $table->boolean('is_current')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable()->comment('Justification du changement');

            $table->timestamp('created_at')->useCurrent();

            $table->unique('version_label');
            $table->unique('is_current', 'dim_rule_versions_current_unique');
            $table->index(['effective_from', 'effective_to'], 'dim_rule_versions_period_index');
        });

        SchemaSupport::addCheck(
            'dim_adoption_rule_versions',
            'chk_rule_versions_thresholds',
            'lost_after_days > at_risk_after_days',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('dim_adoption_rule_versions');
    }
};
