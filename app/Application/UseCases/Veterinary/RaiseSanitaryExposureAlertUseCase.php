<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Core\Entities\SanitaryExposureAlertEntity;
use App\Core\Entities\VeterinaryDiagnosisEntity;
use App\Core\Interfaces\IBullHealthEvaluationRepository;
use App\Core\Interfaces\ISanitaryExposureRepository;
use App\Core\Interfaces\IVeterinaryDiagnosisRepository;
use App\Core\Services\SanitaryExposureResolver;
use App\Core\ValueObjects\SanitaryExposure\ContagiousFinding;
use App\Core\ValueObjects\VenerealSamplingStatus;

/**
 * Opens (or brings up to date) the exposure alert of a bull found positive to a contagious,
 * disqualifying pathogen. Called after every aptitude recompute and after a bull leaves a
 * service; it never blocks and never touches the females' state.
 *
 * Idempotent per bull, pathogen and finding date: a repeated recompute adds the females not
 * yet listed to the open alert, and a resolved alert is not reopened for the same finding.
 */
final class RaiseSanitaryExposureAlertUseCase
{
    public function __construct(
        private readonly SanitaryExposureResolver $resolver,
        private readonly ISanitaryExposureRepository $repository,
        private readonly IVeterinaryDiagnosisRepository $diagnosisRepository,
        private readonly IBullHealthEvaluationRepository $bullHealthRepository
    ) {
    }

    /**
     * @param array<VeterinaryDiagnosisEntity>|null $activeDiagnoses Loaded when null.
     * @return int[] Ids of the alerts opened or updated.
     */
    public function __invoke(
        int $bullId,
        int $companyId,
        ?array $activeDiagnoses = null,
        ?VenerealSamplingStatus $sampling = null
    ): array {
        $activeDiagnoses ??= $this->diagnosisRepository->findActiveByCaravanId($bullId);
        $sampling ??= $this->bullHealthRepository->findVenerealSampling($bullId, $companyId);

        $findings = $this->resolver->contagiousFindings($activeDiagnoses, $sampling);
        if ($findings === []) {
            return [];
        }

        $participations = $this->repository->bullParticipations($bullId, $companyId);
        $exposures = $this->repository->femaleExposures(
            array_values(array_unique(array_map(fn ($p) => $p->serviceOrderId, $participations)))
        );

        $alertIds = [];
        foreach ($findings as $finding) {
            $existing = $this->repository->findByFinding($bullId, $finding->pathogenCode, $finding->detectedOn);
            if ($existing !== null && $existing['status'] !== SanitaryExposureAlertEntity::STATUS_OPEN) {
                continue;
            }

            $overlaps = $this->resolver->overlaps($bullId, $finding->riskWindowFrom, $participations, $exposures);
            $detail = $this->detail($bullId, $finding, count(array_unique(array_map(fn ($o) => $o->femaleId, $overlaps))));

            if ($existing === null) {
                $alertId = $this->repository->create($companyId, $bullId, $finding, $detail);
            } else {
                $alertId = $existing['id'];
                $this->repository->updateDetail($alertId, $detail);
            }

            $this->repository->syncFemales($alertId, $companyId, $overlaps);
            $alertIds[] = $alertId;
        }

        return $alertIds;
    }

    private function detail(int $bullId, ContagiousFinding $finding, int $femaleCount): string
    {
        $bull = $this->repository->bullTag($bullId);
        $pathogen = $finding->pathogenName ?? $finding->pathogenCode;
        $since = $finding->riskWindowFrom->format('d/m/Y');
        $origin = $finding->riskWindowFromLastNegative ? 'último resultado negativo' : 'sin negativo previo: 365 días hacia atrás';

        if ($femaleCount === 0) {
            return "{$bull} positivo a {$pathogen} ({$finding->detectedOn->format('d/m/Y')}). "
                . "No estuvo en servicio con vientres desde {$since} ({$origin}).";
        }

        $females = $femaleCount === 1 ? '1 vientre estuvo' : "{$femaleCount} vientres estuvieron";

        return "{$bull} positivo a {$pathogen} ({$finding->detectedOn->format('d/m/Y')}). "
            . "{$females} en servicio con él desde {$since} ({$origin}).";
    }
}
