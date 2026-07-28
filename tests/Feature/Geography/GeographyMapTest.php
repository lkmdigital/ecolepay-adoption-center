<?php

namespace Tests\Feature\Geography;

use App\Domains\Schools\Support\IvorianGazetteer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeographyMapTest extends TestCase
{
    #[Test]
    public function it_locates_schools_from_recognizable_place_names(): void
    {
        $cocody = IvorianGazetteer::locate('GROUPE SCOLAIRE ANGRE COCODY');
        $this->assertNotNull($cocody);
        $this->assertSame('Abidjan', $cocody['region']);

        $bassam = IvorianGazetteer::locate('COLLÈGE PRIVÉ DE GRAND-BASSAM');
        $this->assertSame('Sud-Comoé', $bassam['region']);
        $this->assertSame('Grand-Bassam', $bassam['city']);
    }

    #[Test]
    public function longer_place_names_win_over_shorter_substrings(): void
    {
        // « GRAND-BASSAM » doit primer sur « BASSAM » (même ville ici) et surtout
        // une ville spécifique ne doit pas être écrasée par un motif plus court.
        $sanpedro = IvorianGazetteer::locate('LYCÉE DE SAN-PEDRO');
        $this->assertSame('San-Pédro', $sanpedro['region']);
    }

    #[Test]
    public function an_unrecognized_name_is_not_located(): void
    {
        $this->assertNull(IvorianGazetteer::locate('MORNING GLORY'));
        $this->assertNull(IvorianGazetteer::locate('LE PETIT ROYAUME'));
    }

    #[Test]
    public function abidjan_neighbourhoods_map_to_the_abidjan_district(): void
    {
        foreach (['EEM MARCORY', 'GS BOYANE ABOBO', 'EP ST JOSEPH DES 2 PLATEAUX', 'EPC SAINT AUGUSTIN DE BINGERVILLE'] as $name) {
            $this->assertSame('Abidjan', IvorianGazetteer::locate($name)['region'], "Échec pour : $name");
        }
    }

    // NB : le rendu complet de la page dépend de ListSchoolsForPilotage, qui
    // utilise DATE_FORMAT (MySQL) et ne tourne pas sous SQLite — il est vérifié
    // en navigateur, comme le Dashboard. Ici on teste la logique du gazetteer.
}
