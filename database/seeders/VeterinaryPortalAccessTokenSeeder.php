<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use App\Models\Veterinarian;
use App\Models\VeterinarianBatchAssignment;
use App\Models\VeterinaryPortalAccessToken;
use Illuminate\Database\Seeder;

/**
 * Issues demo access links so the external entry point can be exercised right after a fresh
 * install. The plaintext is fixed ONLY here, and printed to the console, because a seeded
 * environment has no other way to hand the link over; production tokens are random.
 */
class VeterinaryPortalAccessTokenSeeder extends Seeder
{
    private const DEMO_TOKENS = [
        [
            'license' => 'MP 6710',
            'plain' => 'demo-vet-sosa-token-0000000000000001',
            'label' => 'Raspajes de entore — Dra. Sosa (enlace de demostración)',
            'ttl_days' => 30,
            'scoped_to_batch' => false,
        ],
        [
            'license' => 'MP 3391',
            'plain' => 'demo-vet-quiroga-token-000000000002',
            'label' => 'Serología Brucelosis — Dr. Quiroga (enlace acotado a un lote)',
            'ttl_days' => 7,
            'scoped_to_batch' => true,
        ],
    ];

    public function run(): void
    {
        $company = Company::first();

        if (!$company) {
            $this->command?->warn('VeterinaryPortalAccessTokenSeeder: no hay compañía cargada. Se omite.');

            return;
        }

        $companyId = (int) $company->id;
        $createdBy = User::first()?->id;

        foreach (self::DEMO_TOKENS as $definition) {
            $vet = Veterinarian::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('license_number', $definition['license'])
                ->first();

            if ($vet === null) {
                continue;
            }

            $batchId = null;

            if ($definition['scoped_to_batch']) {
                $batchId = VeterinarianBatchAssignment::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('veterinarian_id', $vet->id)
                    ->whereNull('unassigned_at')
                    ->value('batch_id');

                if ($batchId === null) {
                    continue;
                }
            }

            VeterinaryPortalAccessToken::withoutGlobalScopes()->updateOrCreate(
                // Only the hash is ever stored, exactly as in production.
                ['token_hash' => hash('sha256', $definition['plain'])],
                [
                    'company_id' => $companyId,
                    'veterinarian_id' => $vet->id,
                    'batch_id' => $batchId !== null ? (int) $batchId : null,
                    'token_prefix' => substr($definition['plain'], 0, 8),
                    'label' => $definition['label'],
                    'expires_at' => now()->addDays((int) $definition['ttl_days']),
                    'max_uses' => null,
                    'used_count' => 0,
                    'created_by_user_id' => $createdBy,
                ]
            );

            $this->command?->info(sprintf(
                'Acceso temporal de demo para %s: /vet-portal/%s (vence en %d días)',
                $vet->name,
                $definition['plain'],
                (int) $definition['ttl_days']
            ));
        }
    }
}
