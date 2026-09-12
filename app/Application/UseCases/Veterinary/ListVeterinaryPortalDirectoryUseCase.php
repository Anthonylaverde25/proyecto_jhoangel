<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Core\Enums\DiagnosticProtocolType;
use App\Core\Enums\ProtocolStatus;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IVeterinarianRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One row per professional: whose portal exists, what is waiting inside it, and who holds a key.
 *
 * The establishment already had two ways to reach this, and neither answered the question. The
 * access manager is organised by TOKEN, so a professional with no key is invisible in it; the
 * supervision dropdown names people but says nothing about them. What the producer actually asks
 * is "what is going on in each of my professionals' portals", and that is a list of professionals.
 *
 * Counted in three grouped queries rather than per row: the numbers are the reason to open this
 * screen, so they cannot be the thing that makes it slow.
 */
final class ListVeterinaryPortalDirectoryUseCase
{
    public function __construct(
        private readonly IVeterinarianRepository $veterinarians,
        private readonly ICompanyContext $companyContext
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(bool $activeOnly = true): array
    {
        $companyId = $this->companyContext->getCompanyId() ?? 0;

        $pending = $this->pendingWorkByVeterinarian($companyId);
        $tubes = $this->unshippedTubesByVeterinarian($companyId);
        $keys = $this->activeKeysByVeterinarian($companyId);

        $rows = [];

        foreach ($this->veterinarians->findAll($companyId, $activeOnly) as $vet) {
            $id = (int) $vet->getId();
            $work = $pending[$id] ?? ['pending_signature' => 0, 'pending_lab_report' => 0];
            $key = $keys[$id] ?? null;

            $rows[] = [
                'veterinarian_id' => $id,
                'name' => $vet->getName(),
                'license_number' => $vet->getLicenseNumber(),
                'email' => $vet->getEmail(),
                'phone' => $vet->getPhone(),
                'is_active' => $vet->isActive(),

                // ADR-33: the portal is reachable with an account. Without one the professional
                // depends on a temporary link, which is the case worth seeing at a glance.
                'user_id' => $vet->getUserId(),
                'has_portal_account' => $vet->getUserId() !== null,

                // What is actually waiting in that portal. Zero everywhere means nothing is owed.
                'pending_signature_count' => (int) $work['pending_signature'],
                'pending_lab_report_count' => (int) $work['pending_lab_report'],
                'unshipped_samples_count' => (int) ($tubes[$id] ?? 0),

                // ADR-34: the temporary link survives as a secondary way in.
                'active_token_count' => $key !== null ? (int) $key->active_count : 0,
                'token_expires_at' => $key?->nearest_expiry,
                'last_portal_access_at' => $key?->last_used_at,
            ];
        }

        return $rows;
    }

    /**
     * Acts awaiting a signature, and signed acts awaiting a result.
     *
     * @return array<int, array{pending_signature: int, pending_lab_report: int}>
     */
    private function pendingWorkByVeterinarian(int $companyId): array
    {
        $rows = DB::table('diagnostic_protocols as acts')
            ->select([
                'acts.veterinarian_id',
                DB::raw('SUM(CASE WHEN acts.signed_at IS NULL THEN 1 ELSE 0 END) as pending_signature'),
                DB::raw('SUM(CASE WHEN acts.signed_at IS NOT NULL AND reports.id IS NULL THEN 1 ELSE 0 END) as pending_lab_report'),
            ])
            // A live report closes the act; a voided one leaves it owing a result again.
            ->leftJoin('diagnostic_protocols as reports', function ($join): void {
                $join->on('reports.parent_protocol_id', '=', 'acts.id')
                    ->where('reports.protocol_type', DiagnosticProtocolType::LAB_REPORT->value)
                    ->where('reports.status', '!=', ProtocolStatus::VOIDED->value);
            })
            ->where('acts.company_id', $companyId)
            ->where('acts.protocol_type', DiagnosticProtocolType::EXTRACTION_ACT->value)
            ->where('acts.status', '!=', ProtocolStatus::VOIDED->value)
            ->whereNotNull('acts.veterinarian_id')
            ->groupBy('acts.veterinarian_id')
            ->get();

        $byVet = [];

        foreach ($rows as $row) {
            $byVet[(int) $row->veterinarian_id] = [
                'pending_signature' => (int) $row->pending_signature,
                'pending_lab_report' => (int) $row->pending_lab_report,
            ];
        }

        return $byVet;
    }

    /**
     * ADR-30: tubes still in the professional's hands, counted from the tubes themselves.
     *
     * PHYSICAL tubes, not determinations. `bull_lab_samples` holds one row per determination — a
     * preputial scrape is cultured for both venereal agents, and aptitude is counted per agent —
     * so counting rows told the producer a professional was holding twice the glass they had.
     *
     * Distinct tube numbers, plus the rows carrying none: an unlabelled sample cannot be grouped
     * with another unlabelled one, so each counts for itself.
     *
     * @return array<int, int>
     */
    private function unshippedTubesByVeterinarian(int $companyId): array
    {
        $rows = DB::table('bull_lab_samples as tubes')
            ->select([
                'acts.veterinarian_id',
                DB::raw('COUNT(DISTINCT tubes.tube_number) as labelled_tubes'),
                DB::raw('SUM(CASE WHEN tubes.tube_number IS NULL THEN 1 ELSE 0 END) as unlabelled_tubes'),
            ])
            ->join('diagnostic_protocols as acts', 'acts.id', '=', 'tubes.extraction_act_id')
            ->where('acts.company_id', $companyId)
            ->where('acts.status', '!=', ProtocolStatus::VOIDED->value)
            ->whereNotNull('acts.signed_at')
            ->whereNull('tubes.sample_shipment_id')
            ->where('tubes.status', 'PENDING_RESULTS')
            ->whereNotNull('acts.veterinarian_id')
            ->groupBy('acts.veterinarian_id')
            ->get();

        $byVet = [];

        foreach ($rows as $row) {
            $byVet[(int) $row->veterinarian_id] = (int) $row->labelled_tubes + (int) $row->unlabelled_tubes;
        }

        return $byVet;
    }

    /**
     * Live temporary grants: how many, the soonest to expire, and the last time one was used.
     *
     * @return array<int, object>
     */
    private function activeKeysByVeterinarian(int $companyId): array
    {
        $rows = DB::table('veterinary_portal_access_tokens')
            ->select([
                'veterinarian_id',
                DB::raw('COUNT(id) as active_count'),
                DB::raw('MIN(expires_at) as nearest_expiry'),
                DB::raw('MAX(last_used_at) as last_used_at'),
            ])
            ->where('company_id', $companyId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', Carbon::now())
            ->whereNotNull('veterinarian_id')
            ->groupBy('veterinarian_id')
            ->get();

        $byVet = [];

        foreach ($rows as $row) {
            $byVet[(int) $row->veterinarian_id] = $row;
        }

        return $byVet;
    }
}
