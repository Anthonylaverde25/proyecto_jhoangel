<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Core\Entities\DiagnosticProtocolEntity;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\IDiagnosticProtocolRepository;
use App\Core\Interfaces\IVeterinaryPortalContext;
use App\Models\Batch;
use App\Models\Caravan;

/**
 * Everything the portal needs on load: who the professional is, which batches they may work
 * on, and the bulls inside them with their current sanitary standing. Identical payload for
 * both entry points, because both resolve into IVeterinaryPortalContext.
 */
final class GetVeterinaryPortalWorkspaceUseCase
{
    public function __construct(
        private readonly IVeterinaryPortalContext $portalContext,
        private readonly IDiagnosticProtocolRepository $protocolRepository
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $batchId = null): array
    {
        if (!$this->portalContext->isResolved()) {
            throw VeterinaryDomainException::domainError('Sesión de portal veterinario no resuelta.');
        }

        $companyId = $this->portalContext->getCompanyId();
        $allowedBatchIds = $this->portalContext->getAllowedBatchIds();

        if ($batchId !== null && !$this->portalContext->canAccessBatch($batchId)) {
            throw VeterinaryDomainException::batchNotAssigned($batchId);
        }

        $batches = Batch::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $allowedBatchIds)
            ->orderBy('name')
            ->get(['id', 'name', 'activity_id', 'batch_type_id'])
            ->map(static fn (Batch $batch): array => [
                'id' => (int) $batch->id,
                'name' => (string) $batch->name,
            ])
            ->all();

        $targetBatchIds = $batchId !== null ? [$batchId] : $allowedBatchIds;

        $bulls = $targetBatchIds === [] ? [] : Caravan::query()
            ->with(['bullHealthEvaluations' => static function ($query) {
                $query->orderByDesc('last_evaluation_date')->orderByDesc('id')->limit(1);
            }])
            ->where('company_id', $companyId)
            ->whereIn('batch_id', $targetBatchIds)
            ->whereIn('sex', ['M', 'MACHO', 'MALE'])
            ->orderBy('identification')
            ->get()
            ->map(static function (Caravan $caravan): array {
                $latest = $caravan->bullHealthEvaluations->first();

                return [
                    'caravan_id' => (int) $caravan->id,
                    'identification' => (string) $caravan->identification,
                    'batch_id' => $caravan->batch_id !== null ? (int) $caravan->batch_id : null,
                    'aptitude_status' => $latest?->status ?? 'PENDING_EVALUATION',
                    'scrotal_circumference_cm' => $latest?->scrotal_circumference_cm !== null
                        ? (float) $latest->scrotal_circumference_cm
                        : null,
                    'body_condition_score' => $latest?->body_condition_score !== null
                        ? (float) $latest->body_condition_score
                        : null,
                    'last_evaluation_date' => $latest?->last_evaluation_date?->format('Y-m-d'),
                ];
            })
            ->all();

        $veterinarianId = $this->portalContext->getVeterinarianId();
        $allowedActIds = $this->portalContext->getAllowedProtocolIds();

        return [
            'veterinarian' => [
                'id' => $veterinarianId,
                'name' => $this->portalContext->getVeterinarianName(),
                'license_number' => $this->portalContext->getLicenseNumber(),
            ],

            // The institution that answers for the samples this session handles. Without it the
            // professional cannot tell which laboratory their responsibility runs through.

            'access_mode' => $this->portalContext->getAccessMode()?->value,
            'scoped_to_act_ids' => $allowedActIds,
            'company_id' => $companyId,
            'batches' => $batches,
            'bulls' => $bulls,

            // The professional's inbox: what they owe, split by which act of theirs is waiting.
            'pending_signature' => $this->summarise(
                $this->protocolRepository->findActsPendingSignature($veterinarianId, $companyId),
                $allowedActIds
            ),
            'pending_lab_report' => $this->summarise(
                $this->protocolRepository->findActsPendingLabReport($veterinarianId, $companyId),
                $allowedActIds
            ),
        ];
    }

    /**
     * @param array<DiagnosticProtocolEntity> $acts
     * @param list<int> $allowedActIds
     * @return list<array<string, mixed>>
     */
    private function summarise(array $acts, array $allowedActIds): array
    {
        $summary = [];

        foreach ($acts as $act) {
            // A grant issued for one act must not leak the professional's other work.
            if ($allowedActIds !== [] && !in_array((int) $act->getId(), $allowedActIds, true)) {
                continue;
            }

            $samples = $act->getLabSamples();
            $caravanIds = array_values(array_unique(array_map(
                static fn (array $sample): int => (int) $sample['caravan_id'],
                $samples
            )));

            $summary[] = [
                'id' => $act->getId(),
                'protocol_number' => $act->getProtocolNumber(),
                'sample_date' => $act->getSampleDate()->format('Y-m-d'),
                'status' => $act->getStatus()->value,
                'verification_status' => $act->getVerificationStatus()->value,
                'is_signed' => $act->isSigned(),
                'bulls_count' => count($caravanIds),
                'samples_count' => $act->getSamplesCount(),
                'pending_samples_count' => $act->getPendingSamplesCount(),
                'dispatch_note_number' => $act->getDispatchNoteNumber(),
                'observations' => $act->getObservations(),
            ];
        }

        return $summary;
    }
}
