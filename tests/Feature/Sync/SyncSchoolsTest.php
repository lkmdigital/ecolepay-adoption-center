<?php

namespace Tests\Feature\Sync;

use App\Domains\Schools\Actions\SyncSchools;
use App\Domains\Schools\Models\School;
use App\Infrastructure\EcolePay\EcolePaySource;
use App\Infrastructure\EcolePay\SchoolReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Teste la synchro écoles avec un lecteur factice : aucune base EcolePay requise,
 * donc portable en CI.
 */
class SyncSchoolsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_maps_the_subscription_model_from_the_abonnement_amount(): void
    {
        $this->sync([
            $this->row(1, 'ENED01', 'ENTRAIDE', abonnement: 9900),
            $this->row(2, 'LIJM01', 'MERMOZ', abonnement: 0),
        ]);

        $paid = School::query()->sameSource('1')->current()->first();
        $bundled = School::query()->sameSource('2')->current()->first();

        $this->assertSame('parent_paid', $paid->subscription_model);
        $this->assertSame(9900, $paid->subscription_amount);

        // 0 = l'école prend en charge l'abonnement.
        $this->assertSame('bundled', $bundled->subscription_model);
        $this->assertTrue($bundled->coversSubscription());
    }

    #[Test]
    public function re_syncing_unchanged_data_creates_no_new_version(): void
    {
        $rows = [$this->row(1, 'ENED01', 'ENTRAIDE', abonnement: 9900)];

        $first = $this->sync($rows);
        $second = $this->sync($rows);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, School::query()->sameSource('1')->count());
    }

    #[Test]
    public function a_real_change_opens_a_new_version_and_closes_the_old(): void
    {
        $this->sync([$this->row(1, 'ENED01', 'ANCIEN NOM', abonnement: 9900)]);
        // La synchro est nocturne : la version suivante naît un jour plus tard.
        $this->travel(1)->days();
        $this->sync([$this->row(1, 'ENED01', 'NOUVEAU NOM', abonnement: 9900)]);

        $versions = School::query()->sameSource('1')->orderBy('version')->get();

        $this->assertCount(2, $versions);
        $this->assertNull($versions[0]->is_current);           // v1 clôturée
        $this->assertNotNull($versions[0]->valid_to);
        $this->assertTrue($versions[1]->is_current);            // v2 courante
        $this->assertSame('NOUVEAU NOM', $versions[1]->name);
    }

    #[Test]
    public function a_new_version_preserves_manually_entered_geography(): void
    {
        $this->sync([$this->row(1, 'ENED01', 'ANCIEN NOM', abonnement: 9900)]);

        // Géographie saisie dans EAC, une fois.
        School::query()->sameSource('1')->current()->first()->forceFill([
            'region' => 'Abidjan',
            'city' => 'Cocody',
            'geo_locked' => true,
        ])->save();

        // Un changement venant de la source ne doit pas l'écraser.
        $this->travel(1)->days();
        $this->sync([$this->row(1, 'ENED01', 'NOUVEAU NOM', abonnement: 4900)]);

        $current = School::query()->sameSource('1')->current()->first();

        $this->assertSame(2, $current->version);
        $this->assertSame('NOUVEAU NOM', $current->name);
        $this->assertSame('Abidjan', $current->region);
        $this->assertSame('Cocody', $current->city);
        $this->assertTrue($current->geo_locked);
    }

    /**
     * @param  array<int, object>  $rows
     * @return array{read: int, inserted: int, updated: int, unchanged: int}
     */
    private function sync(array $rows): array
    {
        return (new SyncSchools(new FakeSchoolReader($rows)))();
    }

    private function row(int $id, string $code, string $nom, int $abonnement): object
    {
        return (object) [
            'id' => $id,
            'code' => $code,
            'nom' => $nom,
            'email' => null,
            'abonnement' => $abonnement,
            'actif' => 1,
            'date_add' => '2025-09-01 08:00:00',
        ];
    }
}

/**
 * Lecteur factice : rend les lignes fournies, sans toucher à EcolePay.
 */
class FakeSchoolReader extends SchoolReader
{
    /** @param array<int, object> $rows */
    public function __construct(private readonly array $rows)
    {
        parent::__construct(new EcolePaySource);
    }

    public function all(): LazyCollection
    {
        return LazyCollection::make($this->rows);
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
