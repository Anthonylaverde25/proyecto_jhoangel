<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pathogen;
use Illuminate\Database\Seeder;

class PathogenSeeder extends Seeder
{
    /**
     * Seed the bovine pathogens catalog grounded in Carrillo (1988) "Manejo de un Rodeo de Cría".
     */
    public function run(): void
    {
        $pathogens = [
            [
                'code' => 'TRITRICHOMONAS_FOETUS',
                'name' => 'Tritrichomonas foetus (Tricomoniasis Bovina)',
                'category' => 'VENEREAL',
                'is_disqualifying' => true,
                'description' => 'Protozoo flagelado asintomático en prepucio del toro. En la cópula provoca muerte embrionaria, piómetra, repetición de celos y caídas severas de preñez (Carrillo p. 166, 207).',
            ],
            [
                'code' => 'CAMPYLOBACTER_FETUS',
                'name' => 'Campylobacter fetus subsp. venerealis (Campylobacteriosis / Vibriosis)',
                'category' => 'VENEREAL',
                'is_disqualifying' => true,
                'description' => 'Bacteria móvil transmitida en el coito. Ocasiona infertilidad temporal y abortos en el primer tercio de gestación. Portador permanente (Carrillo p. 173, 207).',
            ],
            [
                'code' => 'BRUCELLA_ABORTUS',
                'name' => 'Brucella abortus (Brucelosis Bovina)',
                'category' => 'SYSTEMIC',
                'is_disqualifying' => true,
                'description' => 'Causa orquitis, epididimitis y semen contagioso. Enfermedad zoonótica sujeta a plan de saneamiento y eliminación oficial obligatoria (Carrillo p. 44, 167).',
            ],
            [
                'code' => 'MYCOBACTERIUM_BOVIS',
                'name' => 'Mycobacterium bovis (Tuberculosis Bovina)',
                'category' => 'SYSTEMIC',
                'is_disqualifying' => true,
                'description' => 'Enfermedad bacteriana crónica granulomatosa. Todo animal reactor a la tuberculinización debe ser eliminado del rodeo (Carrillo p. 44, 80).',
            ],
            [
                'code' => 'FUSOBACTERIUM_NECROPHORUM',
                'name' => 'Fusobacterium necrophorum (Pietín / Pododermatitis Bovina)',
                'category' => 'LOCOMOTOR',
                'is_disqualifying' => false,
                'description' => 'Infección podal necrosante interdigital. Provoca dolor agudo y claudicación impidiendo la monta. Tratable con antibióticos y pediluvios (Carrillo p. 167).',
            ],
            [
                'code' => 'MORAXELLA_BOVIS',
                'name' => 'Moraxella bovis (Queratoconjuntivitis Infecciosa Bovina)',
                'category' => 'OCULAR',
                'is_disqualifying' => false,
                'description' => 'Fotofobia, blefarospasmo y ceguera temporal. Impide la detección visual del celo a la distancia. Tratable con colirios y oxitetraciclina (Carrillo p. 167, 171).',
            ],
            [
                'code' => 'BOVINE_PAPILLOMAVIRUS',
                'name' => 'Papilomavirus Bovino (Papilomatosis Peneana / Verrugas)',
                'category' => 'VENEREAL',
                'is_disqualifying' => false,
                'description' => 'Fibropapilomas en mucosa de glande y prepucio con dolor al salto y hemorragias coitales. Tratable mediante extirpación quirúrgica o autovacuna.',
            ],
            [
                'code' => 'BOVINE_HERPESVIRUS_1',
                'name' => 'Herpesvirus Bovino 1 (Balanopostitis Pustular Infecciosa / IPV)',
                'category' => 'VENEREAL',
                'is_disqualifying' => false,
                'description' => 'Lesiones vesiculares y pustulares dolorosas en pene y prepucio. Requiere reposo sexual y desinfección local hasta cicatrización (Carrillo p. 167).',
            ],
        ];

        foreach ($pathogens as $data) {
            Pathogen::updateOrCreate(
                ['code' => $data['code']],
                $data
            );
        }
    }
}
