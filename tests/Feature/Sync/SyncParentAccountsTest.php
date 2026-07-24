<?php

namespace Tests\Feature\Sync;

use App\Domains\Parents\Actions\SyncParentAccounts;
use App\Domains\Parents\Models\ParentProfile;
use App\Infrastructure\EcolePay\EcolePaySource;
use App\Infrastructure\EcolePay\UserReader;
use App\Infrastructure\Sync\Models\SyncReject;
use App\Infrastructure\Sync\Models\SyncRun;
use App\Shared\Support\PhoneHasher;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncParentAccountsTest extends TestCase
{
    use RefreshDatabase;

    private PhoneHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('eac.phone_hash_key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $this->hasher = new PhoneHasher;
    }

    #[Test]
    public function a_known_parent_becomes_registered(): void
    {
        // Parent connu (issu du roster), sans compte.
        $this->knownParent('0708818239');

        $stats = $this->sync([
            $this->account(740, '0708818239', 'Konan', 'Yao', '2025-03-24 14:39:05'),
        ]);

        $this->assertSame(1, $stats['matched_known']);
        $this->assertSame(0, $stats['new_registered']);

        $parent = ParentProfile::query()->where('phone_e164', '0708818239')->first();
        $this->assertSame('740', $parent->source_parent_id);
        $this->assertNotNull($parent->account_created_at);
        $this->assertSame('Yao Konan', $parent->full_name);
        // Toujours un seul parent : le connu a été mis à niveau, pas dupliqué.
        $this->assertSame(1, ParentProfile::query()->count());
    }

    #[Test]
    public function an_account_with_no_roster_creates_a_new_registered_parent(): void
    {
        $stats = $this->sync([
            $this->account(741, '0767316124', 'Lasme', 'Jp', '2026-04-01 15:29:10'),
        ]);

        $this->assertSame(0, $stats['matched_known']);
        $this->assertSame(1, $stats['new_registered']);
        $this->assertSame(1, ParentProfile::query()->whereNotNull('source_parent_id')->count());
    }

    #[Test]
    public function the_same_number_with_two_accounts_keeps_the_earliest(): void
    {
        $stats = $this->sync([
            $this->account(747, '0748615126', 'Lasme', 'Jp', '2025-03-20 12:02:52'),
            $this->account(745, '0748615126', 'Thourer', 'Tiffany', '2025-03-20 11:57:56'),
        ]);

        $this->assertSame(1, $stats['new_registered']);
        $this->assertSame(1, ParentProfile::query()->count());

        // Compte le plus ancien retenu (745, 11:57:56).
        $parent = ParentProfile::query()->first();
        $this->assertSame('745', $parent->source_parent_id);
        $this->assertSame('2025-03-20 11:57:56', $parent->account_created_at->toDateTimeString());
    }

    #[Test]
    public function an_invalid_phone_is_rejected(): void
    {
        $stats = $this->sync([
            $this->account(1, '0749', 'X', 'Y', '2025-01-01 00:00:00'),
            $this->account(2, '0708818239', 'Ok', 'Ok', '2025-01-01 00:00:00'),
        ]);

        $this->assertSame(1, $stats['rejected']);
        $this->assertSame(1, $stats['new_registered']);
        $this->assertSame(1, SyncReject::query()->where('entity', 'parent_accounts')->count());
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $rows = [$this->account(740, '0708818239', 'Konan', 'Yao', '2025-03-24 14:39:05')];

        $this->sync($rows);
        $second = $this->sync($rows);

        $this->assertSame(0, $second['new_registered']);
        $this->assertSame(0, $second['matched_known']);
        $this->assertSame(1, $second['already']);
        $this->assertSame(1, ParentProfile::query()->count());
    }

    private function knownParent(string $phone): void
    {
        ParentProfile::query()->create([
            'source_parent_id' => null,
            'phone_hash' => $this->hasher->hash(PhoneNumber::tryFrom($phone)),
            'phone_e164' => $phone,
            'first_known_at' => now()->subMonths(2),
            'marketing_consent' => false,
            'is_pseudonymized' => false,
            'is_test' => false,
            'row_hash' => random_bytes(32),
            'synced_at' => now(),
        ]);
    }

    /**
     * @param  list<object>  $rows
     * @return array{read:int,matched_known:int,new_registered:int,already:int,rejected:int}
     */
    private function sync(array $rows): array
    {
        $run = SyncRun::query()->create([
            'source' => 'ecolepay', 'entity' => 'accounts', 'status' => 'running', 'started_at' => now(),
        ]);

        return (new SyncParentAccounts(new FakeUserReader($rows), $this->hasher))($run);
    }

    private function account(int $id, string $tel, string $nom, string $prenom, string $date): object
    {
        return (object) [
            'id_user' => $id,
            'id_ecole' => '1',
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => null,
            'telephone' => $tel,
            'genre' => null,
            'date_ajout' => $date,
            'status' => 1,
        ];
    }
}

class FakeUserReader extends UserReader
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
