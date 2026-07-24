<?php

namespace Tests\Feature\Sync;

use App\Domains\Parents\Models\ParentProfile;
use App\Domains\Schools\Actions\SyncRoster;
use App\Domains\Schools\Models\School;
use App\Domains\Schools\Models\Student;
use App\Infrastructure\EcolePay\EcolePaySource;
use App\Infrastructure\EcolePay\RosterReader;
use App\Infrastructure\Sync\Models\SyncReject;
use App\Infrastructure\Sync\Models\SyncRun;
use App\Shared\Support\PhoneHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncRosterTest extends TestCase
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
    public function it_creates_students_known_parents_and_links(): void
    {
        $school = $this->school('31');

        $stats = $this->sync([
            $this->row(1, 31, 'CRECHE', '0789573044', null),
            $this->row(2, 31, 'CP1', '0708818239', '0555133215'),
        ]);

        $this->assertSame(2, $stats['students']);
        $this->assertSame(3, $stats['known_parents']); // 3 numéros distincts
        $this->assertSame(3, $stats['links']);

        $this->assertSame(2, Student::query()->where('school_id', $school->id)->count());

        // Tous « connus » : aucun compte EcolePay associé pour l'instant.
        $this->assertSame(3, ParentProfile::query()->whereNull('source_parent_id')->count());
    }

    #[Test]
    public function the_same_number_across_two_students_is_one_parent(): void
    {
        $this->school('31');

        // Un parent avec deux enfants dans la même école.
        $stats = $this->sync([
            $this->row(1, 31, 'CP1', '0708818239', null),
            $this->row(2, 31, 'CP2', '0708818239', null),
        ]);

        $this->assertSame(1, $stats['known_parents']);
        $this->assertSame(2, $stats['links']);
        $this->assertSame(1, ParentProfile::query()->count());
    }

    #[Test]
    public function invalid_and_empty_numbers_go_to_rejects(): void
    {
        $this->school('31');

        $stats = $this->sync([
            $this->row(1, 31, 'CP1', '0749', null),      // trop court → rejet
            $this->row(2, 31, 'CP2', '', null),          // vide → ignoré (pas un rejet)
            $this->row(3, 31, 'CP3', '0708818239', null), // valide
        ]);

        $this->assertSame(1, $stats['known_parents']);
        $this->assertSame(1, $stats['rejected']); // seul '0749' est un vrai rejet
        $this->assertSame(1, SyncReject::query()->where('reason_code', 'invalid_phone')->count());
    }

    #[Test]
    public function a_row_whose_school_is_not_synced_is_rejected(): void
    {
        // Aucune école synchronisée.
        $stats = $this->sync([$this->row(1, 999, 'CP1', '0708818239', null)]);

        $this->assertSame(0, $stats['students']);
        $this->assertSame(1, $stats['rejected']);
        $this->assertSame(1, SyncReject::query()->where('reason_code', 'unknown_school')->count());
    }

    #[Test]
    public function re_running_is_idempotent(): void
    {
        $this->school('31');
        $rows = [$this->row(1, 31, 'CP1', '0708818239', '0555133215')];

        $this->sync($rows);
        $second = $this->sync($rows);

        $this->assertSame(0, $second['students']);
        $this->assertSame(0, $second['known_parents']);
        $this->assertSame(0, $second['links']);
        $this->assertSame(1, Student::query()->count());
        $this->assertSame(2, ParentProfile::query()->count());
    }

    /**
     * @param  list<object>  $rows
     * @return array{read:int,students:int,known_parents:int,links:int,rejected:int}
     */
    private function sync(array $rows): array
    {
        $run = SyncRun::query()->create([
            'source' => 'ecolepay', 'entity' => 'roster', 'status' => 'running', 'started_at' => now(),
        ]);

        return (new SyncRoster(new FakeRosterReader($rows), $this->hasher))($run);
    }

    private function school(string $sourceId): School
    {
        return School::factory()->create([
            'source_school_id' => $sourceId,
            'is_test' => false,
            'is_current' => true,
        ]);
    }

    private function row(int $id, int $ecole, string $classe, ?string $tel, ?string $tel2): object
    {
        return (object) [
            'id' => $id,
            'id_ecole' => $ecole,
            'matricule' => 'MAT'.$id,
            'classe' => $classe,
            'nom' => 'Nom',
            'prenom' => 'Prenom',
            'telephone' => $tel,
            'telephone2' => $tel2,
        ];
    }
}

class FakeRosterReader extends RosterReader
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
