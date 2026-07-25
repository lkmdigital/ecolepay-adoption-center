<?php

namespace Tests\Feature\Sync;

use App\Domains\Parents\Actions\SyncPayments;
use App\Domains\Parents\Enums\PaymentStatus;
use App\Domains\Parents\Models\ParentProfile;
use App\Domains\Parents\Models\Payment;
use App\Domains\Schools\Models\School;
use App\Infrastructure\EcolePay\EcolePaySource;
use App\Infrastructure\EcolePay\PaymentReader;
use App\Infrastructure\EcolePay\UserReader;
use App\Infrastructure\Sync\Models\SyncReject;
use App\Infrastructure\Sync\Models\SyncRun;
use App\Shared\Support\PhoneHasher;
use App\Shared\ValueObjects\PhoneNumber;
use Database\Seeders\Shared\CalendarSeeder;
use Database\Seeders\Shared\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private PhoneHasher $hasher;

    private School $school;

    private ParentProfile $parent;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('eac.phone_hash_key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('eac.calendar.start_year', 2025);
        config()->set('eac.calendar.end_year', 2025);
        $this->hasher = new PhoneHasher;

        $this->seed(PaymentMethodSeeder::class);
        $this->seed(CalendarSeeder::class);

        $this->school = School::factory()->create([
            'source_school_id' => '31', 'is_test' => false, 'is_current' => true,
        ]);
        $this->parent = ParentProfile::query()->create([
            'source_parent_id' => '740',
            'phone_hash' => $this->hasher->hash(PhoneNumber::tryFrom('0708818239')),
            'phone_e164' => '0708818239',
            'first_known_at' => now(), 'account_created_at' => now(),
            'marketing_consent' => false, 'is_pseudonymized' => false, 'is_test' => false,
            'row_hash' => random_bytes(32), 'synced_at' => now(),
        ]);
    }

    #[Test]
    public function a_validated_app_payment_is_loaded_as_a_fact(): void
    {
        $stats = $this->sync([
            $this->payer(1, user: 740, ecole: 31, montant: 13940, initial: 4000, abonnement: 9900,
                mode: 'WAVECI', statut: 1, manuel: 0, date: '2025-08-14 11:43:39'),
        ]);

        $this->assertSame(1, $stats['inserted']);
        $payment = Payment::query()->first();

        $this->assertTrue($this->parent->is($payment->parent));
        $this->assertSame(PaymentStatus::Success, $payment->status);
        $this->assertFalse($payment->is_manual);
        // Décomposition du montant.
        $this->assertSame('13940.00', $payment->amount);
        $this->assertSame('4000.00', $payment->net_amount);        // frais scolaires
        $this->assertSame(9900, $payment->subscription_amount);    // part EcolePay
        $this->assertSame('40.00', $payment->fee_amount);          // frais transaction
    }

    #[Test]
    public function a_manual_payment_is_flagged_and_excluded_from_calculations(): void
    {
        $this->sync([
            $this->payer(2, user: 740, ecole: 31, montant: 5000, initial: 5000, abonnement: 0,
                mode: 'ESPECES', statut: 1, manuel: 1, date: '2025-09-01 10:00:00'),
        ]);

        $payment = Payment::query()->first();
        $this->assertTrue($payment->is_manual);
        // Le scope production/adoptant l'exclut.
        $this->assertSame(0, Payment::query()->where('is_manual', false)->successful()->count());
    }

    #[Test]
    public function a_pending_payment_keeps_its_status(): void
    {
        $this->sync([
            $this->payer(3, user: 740, ecole: 31, montant: 10000, initial: 100, abonnement: 9900,
                mode: 'OMCI', statut: 0, manuel: 0, date: '2025-08-20 08:00:00'),
        ]);

        $this->assertSame(PaymentStatus::Pending, Payment::query()->first()->status);
    }

    #[Test]
    public function a_payment_from_an_unknown_user_is_rejected(): void
    {
        $stats = $this->sync([
            $this->payer(4, user: 99999, ecole: 31, montant: 5000, initial: 5000, abonnement: 0,
                mode: 'WAVECI', statut: 1, manuel: 0, date: '2025-08-14 11:00:00'),
        ]);

        $this->assertSame(0, $stats['inserted']);
        $this->assertSame(1, $stats['rejected']);
        $this->assertSame(1, SyncReject::query()->where('reason_code', 'unknown_parent')->count());
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $rows = [$this->payer(1, user: 740, ecole: 31, montant: 13940, initial: 4000, abonnement: 9900,
            mode: 'WAVECI', statut: 1, manuel: 0, date: '2025-08-14 11:43:39')];

        $this->sync($rows);
        $second = $this->sync($rows);

        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, Payment::query()->count());
    }

    /**
     * @param  list<object>  $rows
     * @return array{read:int,inserted:int,unchanged:int,rejected:int}
     */
    private function sync(array $rows): array
    {
        $run = SyncRun::query()->create([
            'source' => 'ecolepay', 'entity' => 'payments', 'status' => 'running', 'started_at' => now(),
        ]);

        $users = [(object) ['id_user' => 740, 'telephone' => '0708818239']];

        return (new SyncPayments(
            new StubPaymentReader($rows),
            new StubUserReader($users),
            $this->hasher,
        ))($run);
    }

    private function payer(int $id, int $user, int $ecole, int $montant, int $initial, int $abonnement, string $mode, int $statut, int $manuel, string $date): object
    {
        return (object) [
            'id' => $id, 'id_ecole' => $ecole, 'id_eleve' => 0, 'id_user' => $user,
            'mode_paiement' => $mode, 'montant_initial' => $initial, 'montant' => $montant,
            'abonnement' => $abonnement, 'reference' => 'REF'.$id, 'date_transaction' => $date,
            'statut' => $statut, 'type' => 'MULTI_PAIEMENT', 'annee_scolaire' => null, 'is_manuel' => $manuel,
        ];
    }
}

class StubPaymentReader extends PaymentReader
{
    /** @param list<object> $rows */
    public function __construct(private readonly array $rows)
    {
        parent::__construct(new EcolePaySource);
    }

    public function chunk(int $size, callable $callback): void
    {
        foreach (array_chunk($this->rows, $size) as $chunk) {
            $callback(new Collection($chunk));
        }
    }
}

class StubUserReader extends UserReader
{
    /** @param list<object> $rows */
    public function __construct(private readonly array $rows)
    {
        parent::__construct(new EcolePaySource);
    }

    public function chunk(int $size, callable $callback): void
    {
        foreach (array_chunk($this->rows, $size) as $chunk) {
            $callback(new Collection($chunk));
        }
    }
}
