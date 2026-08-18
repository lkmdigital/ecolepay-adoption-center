<?php

namespace Tests\Unit\EcolePay;

use App\Infrastructure\EcolePay\ReadOnlyGuard;
use App\Infrastructure\EcolePay\ReadOnlyViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReadOnlyGuardTest extends TestCase
{
    private function guardedConnection(): \Illuminate\Database\Connection
    {
        // Connexion sqlite dédiée qui joue le rôle de la source EcolePay.
        config()->set('database.connections.ecolepay_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $conn = DB::connection('ecolepay_test');

        // La table et ses données sont préparées AVANT de poser le garde
        // (c'est l'équivalent des données déjà présentes côté EcolePay).
        $conn->statement('create table tb_ecole (id integer primary key, nom text)');
        $conn->table('tb_ecole')->insert(['id' => 1, 'nom' => 'Ecole A']);

        ReadOnlyGuard::protect($conn);

        return $conn;
    }

    #[Test]
    public function it_allows_read_queries(): void
    {
        $conn = $this->guardedConnection();

        $this->assertSame('Ecole A', $conn->table('tb_ecole')->where('id', 1)->value('nom'));
        $this->assertSame(1, $conn->table('tb_ecole')->count());
    }

    #[Test]
    public function it_blocks_inserts(): void
    {
        $conn = $this->guardedConnection();

        $this->expectException(ReadOnlyViolationException::class);
        $conn->table('tb_ecole')->insert(['id' => 2, 'nom' => 'Ecole B']);
    }

    #[Test]
    public function it_blocks_updates(): void
    {
        $conn = $this->guardedConnection();

        $this->expectException(ReadOnlyViolationException::class);
        $conn->table('tb_ecole')->where('id', 1)->update(['nom' => 'Piraté']);
    }

    #[Test]
    public function it_blocks_deletes(): void
    {
        $conn = $this->guardedConnection();

        $this->expectException(ReadOnlyViolationException::class);
        $conn->table('tb_ecole')->where('id', 1)->delete();
    }

    #[Test]
    public function it_blocks_raw_ddl(): void
    {
        $conn = $this->guardedConnection();

        $this->expectException(ReadOnlyViolationException::class);
        $conn->statement('drop table tb_ecole');
    }
}
