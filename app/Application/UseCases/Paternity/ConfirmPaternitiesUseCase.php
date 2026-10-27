<?php

declare(strict_types=1);

namespace App\Application\UseCases\Paternity;

use App\Application\DTOs\Paternity\ConfirmPaternityDTO;
use App\Application\Services\Paternity\PaternityCandidates;
use App\Core\Enums\AnimalSex;
use App\Core\Exceptions\PaternityException;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IPaternityRepository;
use Illuminate\Support\Facades\DB;

/**
 * The user confirms the sire of pending calves, all or none: the sire goes to each calf's lineage
 * with how he was identified, and becomes the confirmed sire of its gestation. A sire outside the
 * candidates of the service is kept with a warning, never refused.
 */
final class ConfirmPaternitiesUseCase
{
    public function __construct(
        private readonly IPaternityRepository $repository,
        private readonly PaternityCandidates $candidates,
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    /**
     * @param list<ConfirmPaternityDTO> $confirmations
     * @return array{confirmed: int, warnings: list<array{calf_id: int, message: string}>}
     *
     * @throws PaternityException
     */
    public function __invoke(array $confirmations, int $companyId): array
    {
        $calfIds = array_map(static fn (ConfirmPaternityDTO $c): int => $c->calfId, $confirmations);
        $calves = [];
        foreach ($this->candidates->attach($this->repository->findCalves($companyId, calfIds: $calfIds), $companyId) as $calf) {
            $calves[$calf['calf_id']] = $calf;
        }

        $errors = [];
        $warnings = [];
        $seen = [];
        foreach ($confirmations as $index => $confirmation) {
            $calf = $calves[$confirmation->calfId] ?? null;
            $calfField = "confirmations.{$index}.calf_id";
            $fatherField = "confirmations.{$index}.father_id";

            if ($calf === null) {
                $errors[$calfField] = ['La cría no existe o no tiene registro de nacimiento.'];
                continue;
            }
            $tag = $calf['calf_identification'] ?? "hijo de {$calf['mother_identification']}";
            if (isset($seen[$confirmation->calfId])) {
                $errors[$calfField] = ["La cría {$tag} está repetida en la carga."];
                continue;
            }
            $seen[$confirmation->calfId] = true;
            if ($calf['father_id'] !== null) {
                $errors[$calfField] = ["La cría {$tag} ya tiene padre confirmado."];
                continue;
            }

            $father = $this->caravanRepository->findById($confirmation->fatherId);
            if ($father === null || $father->getCompanyId() !== $companyId) {
                $errors[$fatherField] = ['El padre elegido no existe.'];
                continue;
            }
            if ($father->getSex() !== AnimalSex::MALE) {
                $errors[$fatherField] = ["{$father->getIdentification()->getValue()} no es macho: no puede ser padre."];
                continue;
            }

            $candidateIds = array_column($calf['candidates'], 'id');
            if (!in_array($confirmation->fatherId, $candidateIds, true)) {
                $warnings[] = [
                    'calf_id' => $confirmation->calfId,
                    'message' => "{$father->getIdentification()->getValue()} no estaba entre los toros del servicio de {$tag}: se confirmó igual.",
                ];
            }
        }

        if ($errors !== []) {
            throw PaternityException::many($errors);
        }

        DB::transaction(function () use ($confirmations, $calves): void {
            foreach ($confirmations as $confirmation) {
                $this->repository->confirm(
                    $confirmation->calfId,
                    $confirmation->fatherId,
                    $confirmation->method->value,
                    $confirmation->notes,
                    $calves[$confirmation->calfId]['gestation_id']
                );
            }
        });

        return ['confirmed' => count($confirmations), 'warnings' => $warnings];
    }
}
