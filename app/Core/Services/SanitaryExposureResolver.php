<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Entities\VeterinaryDiagnosisEntity;
use App\Core\ValueObjects\SanitaryExposure\BullParticipation;
use App\Core\ValueObjects\SanitaryExposure\ContagiousFinding;
use App\Core\ValueObjects\SanitaryExposure\ExposureOverlap;
use App\Core\ValueObjects\SanitaryExposure\FemaleExposure;
use App\Core\ValueObjects\VenerealSamplingStatus;
use DateTimeImmutable;

/**
 * Which females a bull positive to a contagious pathogen may have infected.
 *
 * Only recorded periods are crossed: the bull's stay in each service order
 * (service_order_males.joined_at .. retired_at) against each female's exposure in the same
 * order (service_order_females.exposed_from .. exposed_until), both clipped to the risk
 * window. In a controlled service a female was only with the bull she was assigned to.
 */
final class SanitaryExposureResolver
{
    public function __construct(private readonly int $validityDays = 365)
    {
    }

    /**
     * Contagious findings behind an UNFIT verdict: active diagnoses of a disqualifying
     * pathogen and venereal positives no later negative superseded. Biometry and aplomos are
     * not contagious and raise nothing.
     *
     * @param array<VeterinaryDiagnosisEntity> $activeDiagnoses
     * @return list<ContagiousFinding>
     */
    public function contagiousFindings(array $activeDiagnoses, ?VenerealSamplingStatus $sampling): array
    {
        $sampling ??= VenerealSamplingStatus::empty();
        /** @var array<string, array{name: ?string, date: DateTimeImmutable}> $byCode */
        $byCode = [];

        foreach ($activeDiagnoses as $diagnosis) {
            if (!$diagnosis->isActive() || !$diagnosis->isPathogenDisqualifying()) {
                continue;
            }
            $code = $diagnosis->getPathogenCode() ?? "PATHOGEN_{$diagnosis->getPathogenId()}";
            $date = $diagnosis->getDiagnosisDate();
            if (!isset($byCode[$code]) || $date < $byCode[$code]['date']) {
                $byCode[$code] = ['name' => $diagnosis->getPathogenName(), 'date' => $date];
            }
        }

        foreach ($sampling->standingPositives() as $code => $date) {
            if (!isset($byCode[$code])) {
                $byCode[$code] = ['name' => null, 'date' => $date];
            }
        }

        $findings = [];
        foreach ($byCode as $code => $finding) {
            $detectedOn = $finding['date']->setTime(0, 0);
            $lastNegative = $sampling->lastNegativeBefore($code, $detectedOn);
            $findings[] = new ContagiousFinding(
                pathogenCode: $code,
                pathogenName: $finding['name'],
                detectedOn: $detectedOn,
                riskWindowFrom: $lastNegative?->setTime(0, 0) ?? $detectedOn->modify(sprintf('-%d days', $this->validityDays)),
                riskWindowFromLastNegative: $lastNegative !== null
            );
        }

        return $findings;
    }

    /**
     * @param list<BullParticipation> $participations
     * @param list<FemaleExposure> $exposures
     * @return list<ExposureOverlap>
     */
    public function overlaps(int $bullId, DateTimeImmutable $riskWindowFrom, array $participations, array $exposures): array
    {
        $byOrder = [];
        foreach ($exposures as $exposure) {
            $byOrder[$exposure->serviceOrderId][] = $exposure;
        }

        $overlaps = [];
        foreach ($participations as $stay) {
            // The bull's stay inside the risk window. It runs until he left, not until the
            // finding: he kept exposing females until someone took him out.
            $bullFrom = max($stay->joinedAt, $riskWindowFrom);
            $bullUntil = $stay->retiredAt;
            if ($bullUntil !== null && $bullUntil < $bullFrom) {
                continue;
            }

            foreach ($byOrder[$stay->serviceOrderId] ?? [] as $exposure) {
                if ($stay->isControlledService && $exposure->assignedMaleId !== null && $exposure->assignedMaleId !== $bullId) {
                    continue;
                }

                $from = max($bullFrom, $exposure->exposedFrom);
                $until = $this->earliest($bullUntil, $exposure->exposedUntil);
                if ($until !== null && $until < $from) {
                    continue;
                }

                $overlaps[] = new ExposureOverlap($stay->serviceOrderId, $exposure->femaleId, $from, $until);
            }
        }

        return $overlaps;
    }

    private function earliest(?DateTimeImmutable $a, ?DateTimeImmutable $b): ?DateTimeImmutable
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return min($a, $b);
    }
}
