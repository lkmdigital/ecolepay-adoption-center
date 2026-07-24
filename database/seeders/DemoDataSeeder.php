<?php

namespace Database\Seeders;

use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Campaigns\Models\CampaignContact;
use App\Domains\Parents\Enums\PaymentStatus;
use App\Domains\Parents\Models\AdoptionEvent;
use App\Domains\Parents\Models\ParentActivity;
use App\Domains\Parents\Models\ParentJourney;
use App\Domains\Parents\Models\ParentProfile;
use App\Domains\Parents\Models\Payment;
use App\Domains\Schools\Models\School;
use App\Domains\Schools\Models\Student;
use App\Domains\Users\Enums\Role;
use App\Domains\Users\Models\User;
use App\Shared\Enums\AdoptionStageCode;
use App\Shared\Models\AdoptionRuleVersion;
use App\Shared\Models\AdoptionStage;
use App\Shared\Models\CalendarDate;
use App\Shared\Models\Channel;
use App\Shared\Models\EventType;
use App\Shared\Models\PaymentMethod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Jeu de démonstration, cohérent de bout en bout.
 *
 * Tout est marqué `is_test = true` : les indicateurs calculés avec le scope
 * `production()` l'ignorent. Ce seeder n'est jamais appelé par `DatabaseSeeder`.
 *
 *     php artisan db:seed --class="Database\Seeders\DemoDataSeeder"
 *
 * Cohérence recherchée : l'état d'un parcours correspond à son historique de
 * paiements et à sa dernière activité. Un jeu incohérent produirait des écrans
 * plausibles mais impossibles à déboguer.
 */
class DemoDataSeeder extends Seeder
{
    /** Répartition des parents dans l'entonnoir. */
    private const DISTRIBUTION = [
        'known' => 30,
        'registered' => 20,
        'adopter' => 15,
        'engaged' => 20,
        'at_risk' => 10,
        'lost' => 5,
    ];

    private const SCHOOL_COUNT = 25;

    private const PARENTS_PER_SCHOOL = 24;

    /** @var array<string, int> */
    private array $stageIds = [];

    private ?int $ruleVersionId = null;

    public function run(): void
    {
        if (AdoptionStage::query()->doesntExist()) {
            $this->command?->error('Données de référence absentes. Lancez d\'abord ReferenceDataSeeder.');

            return;
        }

        $this->stageIds = AdoptionStage::query()->pluck('id', 'code')->all();
        $this->ruleVersionId = AdoptionRuleVersion::query()->current()->value('id');

        $this->purgePreviousRun();

        $managers = $this->createTeam();
        $schools = $this->createSchools($managers);

        $this->command?->info('Génération des parcours…');
        foreach ($schools as $school) {
            $this->populateSchool($school);
        }

        $this->createCampaigns($schools);

        $this->command?->info(sprintf(
            'Démonstration : %d écoles, %d parents, %d parcours, %d paiements.',
            School::query()->onlyTestData()->count(),
            ParentProfile::query()->onlyTestData()->count(),
            ParentJourney::query()->onlyTestData()->count(),
            Payment::query()->onlyTestData()->count(),
        ));
    }

    /**
     * Rend le seeder rejouable.
     *
     * Sans cette purge, un second passage viole « une seule ligne courante par
     * école » — la contrainte faisant correctement son travail.
     *
     * L'ordre suit les dépendances : enfants d'abord. Les cascades couvriraient
     * une partie du travail, mais l'explicite se relit et se corrige.
     */
    private function purgePreviousRun(): void
    {
        $demoUserIds = User::query()->where('email', 'like', '%@demo.eac')->pluck('id');

        CampaignContact::query()->onlyTestData()->delete();

        // `dim_campaigns` ne porte pas d'indicateur `is_test` : les campagnes de
        // démonstration se repèrent par leur auteur. Suppression physique, la
        // suppression logique laisserait des lignes en base.
        Campaign::query()->whereIn('created_by_user_id', $demoUserIds)->forceDelete();

        AdoptionEvent::query()->onlyTestData()->delete();
        ParentJourney::query()->onlyTestData()->delete();
        ParentActivity::query()->onlyTestData()->delete();
        Payment::query()->onlyTestData()->delete();
        Student::query()->onlyTestData()->delete();
        ParentProfile::query()->onlyTestData()->delete();
        School::query()->onlyTestData()->delete();
    }

    /**
     * Un compte par rôle, pour parcourir l'application sous chaque profil.
     *
     * @return array<int, User>
     */
    private function createTeam(): array
    {
        $commercials = [];

        foreach (Role::cases() as $role) {
            $email = $role->value.'@demo.eac';

            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $role->label().' (démo)',
                    'password' => 'password',
                    'department' => $role->label(),
                    'primary_role_code' => $role->value,
                    'job_title' => $role->label(),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );

            $user->syncRoles([$role->value]);

            if ($role === Role::Commercial) {
                $commercials[] = $user;
            }
        }

        // Deux commerciaux supplémentaires : sans portefeuilles distincts, le
        // filtrage par commercial ne se teste pas.
        foreach (['awa', 'kouassi'] as $slug) {
            $user = User::query()->firstOrCreate(
                ['email' => $slug.'@demo.eac'],
                [
                    'name' => Str::title($slug).' (commercial)',
                    'password' => 'password',
                    'department' => 'Commercial',
                    'primary_role_code' => Role::Commercial->value,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
            $user->syncRoles([Role::Commercial->value]);
            $commercials[] = $user;
        }

        return $commercials;
    }

    /**
     * @param  array<int, User>  $managers
     * @return array<int, School>
     */
    private function createSchools(array $managers): array
    {
        $regions = ['Abidjan', 'Yamoussoukro', 'Bouaké', 'San-Pédro', 'Korhogo'];
        $schools = [];

        for ($i = 1; $i <= self::SCHOOL_COUNT; $i++) {
            $schools[] = School::factory()->test()->create([
                'source_school_id' => sprintf('DEMO-SCH-%03d', $i),
                'name' => 'École '.fake()->lastName(),
                'region' => $regions[$i % count($regions)],
                'account_manager_user_id' => $managers[$i % count($managers)]->id,
                'onboarded_at' => now()->subMonths(random_int(2, 30)),
            ]);
        }

        return $schools;
    }

    private function populateSchool(School $school): void
    {
        $stages = $this->weightedStages(self::PARENTS_PER_SCHOOL);
        $methods = PaymentMethod::query()->active()->pluck('id')->all();
        $qualifyingEvents = EventType::query()->qualifying()->pluck('id')->all();

        foreach ($stages as $stageCode) {
            $stage = AdoptionStageCode::from($stageCode);
            $parent = $this->createParent($stage);

            Student::factory()->test()->create([
                'source_student_id' => 'DEMO-STU-'.Str::upper(Str::random(10)),
                'school_id' => $school->id,
            ])->parents()->attach($parent->id, [
                'relationship' => fake()->randomElement(['pere', 'mere']),
                'is_primary_payer' => true,
                'valid_from' => now()->subMonths(10)->toDateString(),
            ]);

            $timeline = $this->buildTimeline($stage);
            $payments = $this->createPayments($parent, $school, $stage, $timeline, $methods);
            $this->createActivities($parent, $school, $timeline, $qualifyingEvents);
            $this->createJourney($parent, $school, $stage, $timeline, $payments);
            $this->createAdoptionEvents($parent, $school, $stage, $timeline);
        }
    }

    /**
     * Répartit les parents dans l'entonnoir selon la distribution cible, puis
     * mélange pour éviter que l'ordre d'insertion ne corrèle avec l'état.
     *
     * @return array<int, string>
     */
    private function weightedStages(int $total): array
    {
        $stages = [];

        foreach (self::DISTRIBUTION as $code => $weight) {
            $count = (int) round($total * $weight / 100);
            $stages = [...$stages, ...array_fill(0, max($count, 1), $code)];
        }

        shuffle($stages);

        return array_slice($stages, 0, $total);
    }

    private function createParent(AdoptionStageCode $stage): ParentProfile
    {
        $factory = ParentProfile::factory()->state(['is_test' => true]);

        // Un « parent connu » n'a pas de compte EcolePay : c'est ce qui rend le
        // premier étage de l'entonnoir mesurable.
        return $stage === AdoptionStageCode::Known
            ? $factory->withoutAccount()->create()
            : $factory->create();
    }

    /**
     * Jalons cohérents avec l'état visé.
     *
     * @return array<string, Carbon|null>
     */
    private function buildTimeline(AdoptionStageCode $stage): array
    {
        $known = now()->subDays(random_int(180, 900));

        $registered = $stage === AdoptionStageCode::Known
            ? null
            : $known->copy()->addDays(random_int(1, 60));

        $firstPayment = $registered && $stage->isConverted()
            ? $registered->copy()->addDays(random_int(1, 45))
            : null;

        $lastActivity = match ($stage) {
            AdoptionStageCode::Known => null,
            // En deçà du seuil « à risque » : au-delà, le traitement d'inactivité
            // les aurait déjà fait basculer, et le jeu serait incohérent avec sa
            // propre règle.
            AdoptionStageCode::Registered => now()->subDays(random_int(
                0,
                config('eac.adoption.at_risk_after_days') - 1,
            )),
            AdoptionStageCode::Adopter, AdoptionStageCode::Engaged => now()->subDays(random_int(0, 30)),
            // Au-delà du seuil « à risque » mais en deçà de « perdu ».
            AdoptionStageCode::AtRisk => now()->subDays(random_int(
                config('eac.adoption.at_risk_after_days'),
                config('eac.adoption.lost_after_days') - 1,
            )),
            AdoptionStageCode::Lost => now()->subDays(random_int(
                config('eac.adoption.lost_after_days'),
                config('eac.adoption.lost_after_days') + 200,
            )),
        };

        return [
            'known' => $known,
            'registered' => $registered,
            'first_payment' => $firstPayment,
            'last_activity' => $lastActivity,
        ];
    }

    /**
     * @param  array<string, Carbon|null>  $timeline
     * @param  array<int, int>  $methods
     * @return array{count: int, success: int, failed: int, amount: float}
     */
    private function createPayments(
        ParentProfile $parent,
        School $school,
        AdoptionStageCode $stage,
        array $timeline,
        array $methods,
    ): array {
        $totals = ['count' => 0, 'success' => 0, 'failed' => 0, 'amount' => 0.0];

        if ($timeline['first_payment'] === null) {
            // Un inscrit sur trois a tenté un paiement et échoué : c'est la
            // meilleure liste d'appels du Commercial.
            if ($stage === AdoptionStageCode::Registered && random_int(1, 3) === 1) {
                $this->insertPayment($parent, $school, $methods, $timeline['registered']->copy()->addDays(5), PaymentStatus::Failed, true);
                $totals['count'] = $totals['failed'] = 1;
            }

            return $totals;
        }

        $successCount = match ($stage) {
            AdoptionStageCode::Adopter => 1,
            AdoptionStageCode::Engaged => random_int(3, 8),
            default => random_int(1, 4),
        };

        $date = $timeline['first_payment']->copy();

        for ($i = 0; $i < $successCount; $i++) {
            $amount = random_int(15, 120) * 1000;
            $this->insertPayment($parent, $school, $methods, $date, PaymentStatus::Success, $i === 0, $i + 1);
            $totals['amount'] += $amount;
            $totals['success']++;
            $totals['count']++;
            // Cycle trimestriel.
            $date->addDays(random_int(75, 110));

            if ($date->isFuture()) {
                break;
            }
        }

        return $totals;
    }

    /**
     * @param  array<int, int>  $methods
     */
    private function insertPayment(
        ParentProfile $parent,
        School $school,
        array $methods,
        Carbon $date,
        PaymentStatus $status,
        bool $isFirst,
        int $sequence = 1,
    ): void {
        $amount = random_int(15, 120) * 1000;

        Payment::query()->create([
            'source_payment_id' => 'DEMO-PAY-'.Str::upper(Str::random(14)),
            'date_id' => CalendarDate::keyFor($date),
            'paid_at' => $date,
            'parent_id' => $parent->id,
            'school_id' => $school->id,
            'payment_method_id' => $methods[array_rand($methods)],
            'amount' => $amount,
            'fee_amount' => round($amount * 0.015, 2),
            'net_amount' => round($amount * 0.985, 2),
            'currency' => config('eac.currency'),
            'status' => $status,
            'failure_reason' => $status === PaymentStatus::Failed ? 'Solde insuffisant' : null,
            'is_first_payment' => $isFirst,
            'payment_sequence' => $sequence,
            'school_year_label' => '2025-2026',
            'is_test' => true,
            'synced_at' => now(),
        ]);
    }

    /**
     * @param  array<string, Carbon|null>  $timeline
     * @param  array<int, int>  $eventTypes
     */
    private function createActivities(ParentProfile $parent, School $school, array $timeline, array $eventTypes): void
    {
        if ($timeline['last_activity'] === null || $eventTypes === []) {
            return;
        }

        $rows = [];
        $date = $timeline['last_activity']->copy();

        for ($i = 0; $i < random_int(3, 15); $i++) {
            $rows[] = [
                'source_event_id' => 'DEMO-EVT-'.Str::upper(Str::random(14)),
                'date_id' => CalendarDate::keyFor($date),
                'occurred_at' => $date,
                'parent_id' => $parent->id,
                'school_id' => $school->id,
                'event_type_id' => $eventTypes[array_rand($eventTypes)],
                'platform' => fake()->randomElement(['android', 'ios', 'web']),
                'is_test' => true,
                'synced_at' => now(),
            ];

            $date->subDays(random_int(1, 25));

            if ($timeline['registered'] && $date->lessThan($timeline['registered'])) {
                break;
            }
        }

        ParentActivity::query()->insert($rows);
    }

    /**
     * @param  array<string, Carbon|null>  $timeline
     * @param  array{count: int, success: int, failed: int, amount: float}  $payments
     */
    private function createJourney(
        ParentProfile $parent,
        School $school,
        AdoptionStageCode $stage,
        array $timeline,
        array $payments,
    ): void {
        $daysSinceActivity = $timeline['last_activity']?->diffInDays(now());

        ParentJourney::query()->create([
            'parent_id' => $parent->id,
            'school_id' => $school->id,
            'current_stage_id' => $this->stageIds[$stage->value],
            'rule_version_id' => $this->ruleVersionId,

            'known_date_id' => CalendarDate::keyFor($timeline['known']),
            'known_at' => $timeline['known'],
            'registered_date_id' => $timeline['registered'] ? CalendarDate::keyFor($timeline['registered']) : null,
            'registered_at' => $timeline['registered'],
            'first_payment_date_id' => $timeline['first_payment'] ? CalendarDate::keyFor($timeline['first_payment']) : null,
            'first_payment_at' => $timeline['first_payment'],
            'last_activity_date_id' => $timeline['last_activity'] ? CalendarDate::keyFor($timeline['last_activity']) : null,
            'last_activity_at' => $timeline['last_activity'],

            'days_known_to_registered' => $timeline['registered']
                ? (int) $timeline['known']->diffInDays($timeline['registered'])
                : null,
            'days_registered_to_first_payment' => $timeline['registered'] && $timeline['first_payment']
                ? (int) $timeline['registered']->diffInDays($timeline['first_payment'])
                : null,
            'days_to_adoption' => $timeline['first_payment']
                ? (int) $timeline['known']->diffInDays($timeline['first_payment'])
                : null,
            'days_since_last_activity' => $daysSinceActivity !== null ? (int) $daysSinceActivity : null,

            'payment_count' => $payments['count'],
            'successful_payment_count' => $payments['success'],
            'failed_payment_count' => $payments['failed'],
            'total_amount' => $payments['amount'],
            'avg_payment_amount' => $payments['success'] > 0 ? $payments['amount'] / $payments['success'] : null,

            'is_converted' => $stage->isConverted(),
            'is_active' => $stage->isActive(),
            'has_ever_paid' => $payments['success'] > 0,
            'is_test' => true,

            'first_built_at' => now(),
            'last_recomputed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, Carbon|null>  $timeline
     */
    private function createAdoptionEvents(ParentProfile $parent, School $school, AdoptionStageCode $stage, array $timeline): void
    {
        $transitions = [[null, AdoptionStageCode::Known, $timeline['known'], 'sync']];

        if ($timeline['registered']) {
            $transitions[] = [AdoptionStageCode::Known, AdoptionStageCode::Registered, $timeline['registered'], 'registration'];
        }

        if ($timeline['first_payment']) {
            $transitions[] = [AdoptionStageCode::Registered, AdoptionStageCode::Adopter, $timeline['first_payment'], 'payment'];
        }

        if (in_array($stage, [AdoptionStageCode::Engaged, AdoptionStageCode::AtRisk, AdoptionStageCode::Lost], true)) {
            $transitions[] = [AdoptionStageCode::Adopter, AdoptionStageCode::Engaged, $timeline['first_payment']?->copy()->addDays(95), 'payment'];
        }

        // Ces deux transitions sont déduites d'une règle, jamais observées.
        if ($stage === AdoptionStageCode::AtRisk || $stage === AdoptionStageCode::Lost) {
            $transitions[] = [AdoptionStageCode::Engaged, AdoptionStageCode::AtRisk,
                $timeline['last_activity']?->copy()->addDays(config('eac.adoption.at_risk_after_days')), 'inactivity_rule'];
        }

        if ($stage === AdoptionStageCode::Lost) {
            $transitions[] = [AdoptionStageCode::AtRisk, AdoptionStageCode::Lost,
                $timeline['last_activity']?->copy()->addDays(config('eac.adoption.lost_after_days')), 'inactivity_rule'];
        }

        foreach ($transitions as [$from, $to, $when, $trigger]) {
            if ($when === null || $when->isFuture()) {
                continue;
            }

            AdoptionEvent::query()->create([
                'date_id' => CalendarDate::keyFor($when),
                'occurred_at' => $when,
                'parent_id' => $parent->id,
                'school_id' => $school->id,
                'from_stage_id' => $from ? $this->stageIds[$from->value] : null,
                'to_stage_id' => $this->stageIds[$to->value],
                'trigger_type' => $trigger,
                'rule_version_id' => $trigger === 'inactivity_rule' ? $this->ruleVersionId : null,
                'is_progression' => $from === null || $to->funnelRank() > $from->funnelRank(),
                'is_regression' => $from !== null && $to->funnelRank() < $from->funnelRank(),
                'is_reactivation' => false,
                'is_test' => true,
                'computed_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<int, School>  $schools
     */
    private function createCampaigns(array $schools): void
    {
        $sms = Channel::query()->where('code', 'sms')->first();
        $whatsapp = Channel::query()->where('code', 'whatsapp')->first();
        $author = User::query()->where('email', 'marketing@demo.eac')->first();

        if (! $sms || ! $author) {
            return;
        }

        $definitions = [
            ['Relance inscrits non payeurs', 'conversion', AdoptionStageCode::Registered, $sms, CampaignStatus::Sent, 25],
            ['Réactivation parents à risque', 'reactivation', AdoptionStageCode::AtRisk, $whatsapp ?? $sms, CampaignStatus::Sent, 5],
            ['Rentrée 2026', 'information', null, $sms, CampaignStatus::Draft, null],
        ];

        foreach ($definitions as [$name, $objective, $targetStage, $channel, $status, $daysAgo]) {
            $campaign = Campaign::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::random(5),
                'objective' => $objective,
                'target_stage_id' => $targetStage ? $this->stageIds[$targetStage->value] : null,
                'channel_id' => $channel->id,
                'target_segment' => ['stage' => $targetStage?->value, 'region' => 'Abidjan'],
                'message_template' => 'Bonjour, réglez vos frais de scolarité sur EcolePay.',
                'status' => $status,
                'currency' => config('eac.currency'),
                'attribution_window_days' => 14,
                'created_by_user_id' => $author->id,
                'started_at' => $daysAgo ? now()->subDays($daysAgo) : null,
                'completed_at' => $daysAgo ? now()->subDays($daysAgo) : null,
            ]);

            if ($status !== CampaignStatus::Sent || $targetStage === null) {
                continue;
            }

            $this->createContacts($campaign, $targetStage, $channel, $daysAgo);
        }
    }

    private function createContacts(Campaign $campaign, AdoptionStageCode $targetStage, Channel $channel, int $daysAgo): void
    {
        $sentAt = now()->subDays($daysAgo);

        $targets = ParentJourney::query()
            ->onlyTestData()
            ->where('current_stage_id', $this->stageIds[$targetStage->value])
            ->limit(150)
            ->get(['parent_id', 'school_id']);

        $rows = [];

        foreach ($targets as $target) {
            $delivered = random_int(1, 10) > 1;
            $opened = $delivered && random_int(1, 10) > 4;

            $rows[] = [
                'campaign_id' => $campaign->id,
                'parent_id' => $target->parent_id,
                'school_id' => $target->school_id,
                'channel_id' => $channel->id,
                'date_id' => CalendarDate::keyFor($sentAt),
                'attempt_number' => 1,
                'sent_at' => $sentAt,
                'delivered_at' => $delivered ? $sentAt->copy()->addMinutes(random_int(1, 30)) : null,
                'opened_at' => $opened ? $sentAt->copy()->addHours(random_int(1, 20)) : null,
                'delivery_status' => $opened ? 'opened' : ($delivered ? 'delivered' : 'failed'),
                'failure_reason' => $delivered ? null : 'Numéro injoignable',
                'actual_cost' => $channel->default_unit_cost,
                'currency' => config('eac.currency'),
                // État figé à l'envoi : sans lui, impossible de juger le ciblage
                // a posteriori, l'état du parent ayant pu changer depuis.
                'stage_id_at_send' => $this->stageIds[$targetStage->value],
                'is_test' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            CampaignContact::query()->insert($chunk);
        }

        $campaign->update(['recipient_count' => count($rows)]);
    }
}
