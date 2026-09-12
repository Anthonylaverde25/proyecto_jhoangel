<?php

declare(strict_types=1);

namespace App\Application\UseCases\PreService;

use App\Application\DTOs\PreService\BullEvaluationSheetLineDTO;
use App\Application\DTOs\PreService\RegisterBullEvaluationSheetDTO;
use App\Application\Services\ProtocolNumberGenerator;
use App\Application\Services\RecomputeBullAptitudeBulkService;
use App\Core\Entities\DiagnosticProtocolEntity;
use App\Core\Enums\DiagnosticProtocolType;
use App\Core\Enums\DiagnosticSourceChannel;
use App\Core\Enums\LabSampleStatus;
use App\Core\Enums\ProtocolStatus;
use App\Core\Enums\ProtocolVerificationStatus;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\IDiagnosticProtocolRepository;
use App\Core\Interfaces\IVeterinarianRepository;
use App\Models\Caravan;
use App\Application\Services\PersistProtocolDetailsService;
use App\Core\Enums\SampleDestinationPlan;
use App\Core\ValueObjects\InstitutionMeta;
use App\Models\DiagnosticProtocol;
use App\Models\Pathogen;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The chute sheet as the opening of a sanitary chain of custody.
 *
 * One pass through the chute emits ONE extraction act (the "acta de manga"), hangs every tube
 * off it, and records the biometry. What it deliberately does NOT do is sign:
 *
 *   ADR-13 — the producer operates the keyboard, but a signature is a professional act. The act
 *   is born DRAFT / UNVERIFIED and only the acting veterinarian can confirm it, from the portal,
 *   which may well be their phone at the chute two minutes later.
 *
 *   ADR-12 — the act number is minted here; the laboratory report number does not exist yet.
 */
final class RegisterBullEvaluationSheetUseCase
{
    public function __construct(
        private readonly IDiagnosticProtocolRepository $protocolRepository,
        private readonly IVeterinarianRepository $veterinarianRepository,
        private readonly ProtocolNumberGenerator $numberGenerator,
        private readonly RecomputeBullAptitudeBulkService $recomputeAptitude,
        private readonly PersistProtocolDetailsService $protocolDetails
    ) {
    }

    /**
     * @throws VeterinaryDomainException
     */
    public function __invoke(RegisterBullEvaluationSheetDTO $dto): DiagnosticProtocolEntity
    {
        if ($dto->bulls === []) {
            throw VeterinaryDomainException::domainError('La planilla no contiene reproductores.');
        }

        $veterinarian = $this->veterinarianRepository->findById($dto->veterinarianId, $dto->companyId);

        if ($veterinarian === null) {
            throw VeterinaryDomainException::veterinarianNotFound($dto->veterinarianId);
        }

        $this->assertCaravansAreEligible($dto);

        $pathogenIds = $this->resolveAssayPathogens();


        return DB::transaction(function () use ($dto, $pathogenIds): DiagnosticProtocolEntity {
            // Minted inside the transaction: the sequence row stays locked until commit.
            $actNumber = $this->numberGenerator->nextExtractionActNumber($dto->companyId, $dto->evaluationDate);

            $act = DiagnosticProtocol::create([
                'company_id' => $dto->companyId,
                'protocol_number' => $actNumber,
                'protocol_type' => DiagnosticProtocolType::EXTRACTION_ACT->value,
                'parent_protocol_id' => null,
                'veterinarian_id' => $dto->veterinarianId,
                // ADR-26: the act's own date is now explicitly the OPENING date of the document,
                // not the date every tube was drawn.
                'sample_date' => $dto->evaluationDate,
                // ADR-11: an extraction act has no result date. The laboratory has not spoken.
                'result_date' => null,
                // The producer's screen prepared this document. It flips to PORTAL_VET the moment
                // the professional signs it.
                'source_channel' => DiagnosticSourceChannel::OWNER_DIGITIZED->value,
                'status' => ProtocolStatus::DRAFT->value,
                'verification_status' => ProtocolVerificationStatus::UNVERIFIED->value,
                'signed_at' => null,
                'signed_by_veterinarian_id' => null,
                'signed_license_number' => null,
                'signed_veterinarian_name' => null,
                'observations' => $dto->observations ?? 'Acta de evaluación andrológica en manga.',
                'created_by_user_id' => $dto->registeredByUserId,
            ]);

            $actId = (int) $act->id;

            // ADR-39: what the professional declared at the chute. Editable while the act is a
            // draft, frozen once they sign it.
            $this->protocolDetails->upsertActDetail(
                protocolId: $actId,
                institution: InstitutionMeta::fromNullableArray($dto->institution),
                destinationPlan: SampleDestinationPlan::fromNullable($dto->destinationPlan),
                dispatchNoteNumber: $dto->dispatchNoteNumber,
                dispatchedAt: $dto->dispatchedAt
            );

            $this->persistBiometry($dto);
            $this->persistDrawnSamples($dto, $actId, $pathogenIds);

            // Runs even though nothing here counts for aptitude yet (ADR-17): the biometry alone
            // can already disqualify a bull, and the tubes move him to PENDING_EVALUATION.
            $this->recomputeAptitude->__invoke($dto->caravanIds(), $dto->companyId, $dto->evaluationDate);

            $entity = $this->protocolRepository->findById($actId, $dto->companyId);

            if ($entity === null) {
                throw VeterinaryDomainException::protocolNotFound($actId);
            }

            return $entity;
        });
    }

    private function assertCaravansAreEligible(RegisterBullEvaluationSheetDTO $dto): void
    {
        $caravanIds = $dto->caravanIds();

        $eligible = Caravan::query()
            ->where('company_id', $dto->companyId)
            ->whereIn('id', $caravanIds)
            ->whereIn('sex', ['M', 'MACHO', 'MALE'])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $rejected = array_diff($caravanIds, $eligible);

        if ($rejected !== []) {
            throw VeterinaryDomainException::caravanNotOwnedOrNotMale(implode(', ', $rejected));
        }
    }

    /**
     * A single tube can rule out several agents, and the engine counts rounds per agent, so the
     * assay is expanded using the mapping in config/livestock.php.
     *
     * @return array<string, list<int>> Assay type => pathogen ids.
     */
    private function resolveAssayPathogens(): array
    {
        /** @var array<string, list<string>> $map */
        $map = (array) config('livestock.sampling.assay_pathogen_codes', []);

        $byCode = Pathogen::query()
            ->whereIn('code', array_values(array_unique(array_merge(...array_values($map) ?: [[]]))))
            ->pluck('id', 'code');

        $resolved = [];

        foreach ($map as $assay => $codes) {
            $ids = [];

            foreach ($codes as $code) {
                if ($byCode->has($code)) {
                    $ids[] = (int) $byCode->get($code);
                }
            }

            $resolved[$assay] = $ids;
        }

        return $resolved;
    }

    private function persistBiometry(RegisterBullEvaluationSheetDTO $dto): void
    {
        $now = Carbon::now();
        $rows = [];

        /** @var BullEvaluationSheetLineDTO $line */
        foreach ($dto->bulls as $line) {
            if (!$line->hasBiometry()) {
                continue;
            }

            $rows[] = [
                'company_id' => $dto->companyId,
                'caravan_id' => $line->caravanId,
                'last_evaluation_date' => $dto->evaluationDate,
                'aplomo_notes' => $line->aplomoNotes,
                'scrotal_circumference_cm' => $line->scrotalCircumferenceCm,
                'body_condition_score' => $line->bodyConditionScore,
                'libido' => $line->libido,
                // Provisional: the bulk recompute overwrites it in the same transaction.
                'status' => 'PENDING_EVALUATION',
                'observations' => $line->observations,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('bull_health_evaluations')->insert($rows);
        }
    }

    /**
     * @param array<string, list<int>> $pathogenIds
     */
    private function persistDrawnSamples(
        RegisterBullEvaluationSheetDTO $dto,
        int $actId,
        array $pathogenIds
    ): void {
        $now = Carbon::now();
        $rows = [];

        /** @var BullEvaluationSheetLineDTO $line */
        foreach ($dto->bulls as $line) {
            // ADR-26: each row may carry its own chute day; the act's date is only the fallback.
            $extractedOn = $line->extractedOn ?? $dto->evaluationDate;

            foreach ($line->assays() as $assay => $tubeNumber) {
                foreach ($pathogenIds[$assay] ?? [] as $pathogenId) {
                    $rows[] = [
                        'company_id' => $dto->companyId,
                        'caravan_id' => $line->caravanId,
                        // The tube belongs to the act from birth; the laboratory report that
                        // resolves it is attached later and fills diagnostic_protocol_id.
                        'extraction_act_id' => $actId,
                        'diagnostic_protocol_id' => null,
                        'veterinarian_id' => $dto->veterinarianId,
                        'evaluation_id' => null,
                        'sample_type' => $assay,
                        'sample_round' => $dto->sampleRound,
                        'sample_date' => $extractedOn,
                        'extracted_on' => $extractedOn,
                        'tube_number' => $tubeNumber,
                        'status' => LabSampleStatus::PENDING_RESULTS->value,
                        // ADR-30: NULL until a dispatch is declared, and it may stay NULL
                        // forever if the professional processes the sample themselves.
                        'sample_shipment_id' => null,
                        'protocol_number' => null,
                        'result_date' => null,
                        'pathogen_id' => $pathogenId,
                        'notes' => 'Muestra tomada en manga.',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        if ($rows !== []) {
            DB::table('bull_lab_samples')->insert($rows);
        }
    }
}
