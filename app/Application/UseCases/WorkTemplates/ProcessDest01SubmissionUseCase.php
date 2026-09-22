<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Application\DTOs\BulkWeanDTO;
use App\Application\DTOs\CreateBatchDTO;
use App\Application\DTOs\Dest01\Dest01SubmissionDTO;
use App\Application\DTOs\WeanCaravanDTO;
use App\Application\UseCases\Caravans\BulkWeanCaravansUseCase;
use App\Core\Entities\BatchEntity;
use App\Core\Entities\CaravanEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Exceptions\Dest01ValidationException;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IActivityRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IBatchTypeRepository;
use App\Core\Interfaces\ICaravanLineageRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\CaravanNumber;

/**
 * DEST-01: turns a weaning sheet (one or several scanned pages) into the weaning of every calf
 * listed, moving them to the weaning batch the operator declared, existing or new.
 *
 * All or nothing: every problem on the sheet is collected and reported together, and nothing
 * is persisted until the sheet is clean. Persistence is delegated to BulkWeanCaravansUseCase,
 * the same path the bulk weaning dialog uses.
 *
 * The mother's tag and the weight are optional and not validated for now.
 */
final class ProcessDest01SubmissionUseCase
{
    private const WEANING_BATCH_TYPE = 'WEANING';
    private const DEFAULT_ACTIVITY = 'CRIA';

    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICaravanLineageRepository $lineageRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly IBatchTypeRepository $batchTypeRepository,
        private readonly IActivityRepository $activityRepository,
        private readonly BulkWeanCaravansUseCase $bulkWean
    ) {
    }

    /**
     * @return array{batch: BatchEntity, created: bool, calves_count: int, males_count: int, females_count: int, weighed_count: int, average_weight: ?float}
     *
     * @throws Dest01ValidationException
     * @throws DomainException
     */
    public function __invoke(Dest01SubmissionDTO $dto): array
    {
        $headerErrors = [];

        // 1. Header: the declared destination and the date.
        $targetBatch = null;
        $weaningBatchTypeId = null;

        if ($dto->targetBatchId !== null) {
            $targetBatch = $this->batchRepository->findById($dto->targetBatchId);
            if (
                $targetBatch === null
                || !$targetBatch->isActive()
                || $targetBatch->getBatchTypeCode() !== self::WEANING_BATCH_TYPE
            ) {
                $targetBatch = null;
                $headerErrors[] = $this->headerError('lote_destete', 'BATCH_NOT_FOUND', 'El lote de destete elegido no existe, está cerrado o no es un lote de destete.');
            }
        } elseif ($dto->newBatchName !== null) {
            $batchType = $this->batchTypeRepository->findByCodeAndCompany(self::WEANING_BATCH_TYPE, $dto->companyId);
            if ($batchType === null) {
                $headerErrors[] = $this->headerError('lote_destete', 'WEANING_BATCH_TYPE_MISSING', 'El tipo de lote Destete no está habilitado para la empresa.');
            } else {
                $weaningBatchTypeId = $batchType->getId();
            }

            if ($this->batchRepository->findActiveByName($dto->newBatchName) !== null) {
                $headerErrors[] = $this->headerError('lote_destete', 'BATCH_NAME_IN_USE', "Ya existe un lote activo llamado '{$dto->newBatchName}'. Elíjalo como lote existente o cambie el nombre.");
            }
        } else {
            $headerErrors[] = $this->headerError('lote_destete', 'BATCH_TARGET_MISSING', 'Falta indicar el lote de destete.');
        }

        $weaningDate = substr($dto->fechaDestete, 0, 10);
        if ($weaningDate > (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            $headerErrors[] = $this->headerError('fecha_destete', 'FUTURE_DATE', 'La fecha de destete no puede ser posterior a hoy.');
        }

        // 2. Rows: resolve every calf, collecting all problems per row.
        /** @var array<int, CaravanEntity> $calvesByRow */
        $calvesByRow = [];
        /** @var array<int, array<int, array{code: string, message: string}>> $errorsByRow */
        $errorsByRow = [];
        $seenTags = [];

        foreach ($dto->rows as $index => $row) {
            $tag = $row['caravana'];
            if ($tag === '') {
                // Blank lines of the printed table, not a missing calf.
                continue;
            }

            $key = mb_strtoupper($tag);
            if (isset($seenTags[$key])) {
                $errorsByRow[$index][] = $this->error('DUPLICATED_IN_SHEET', "La caravana '{$tag}' ya figura en la fila " . ($seenTags[$key] + 1) . '.');
                continue;
            }
            $seenTags[$key] = $index;

            $calf = $this->caravanRepository->findByIdentification(new CaravanNumber($tag));
            if ($calf === null) {
                $errorsByRow[$index][] = $this->error('NOT_FOUND', "No existe la caravana '{$tag}'.");
                continue;
            }

            $calvesByRow[$index] = $calf;
        }

        if (empty($seenTags)) {
            $headerErrors[] = $this->headerError('rows', 'NO_CALVES', 'La planilla no tiene crías cargadas.');
        }

        // 3. Lineage of every calf found, in one query.
        $lineages = $this->lineageRepository->findByCaravanIds(
            array_values(array_map(fn (CaravanEntity $c) => (int) $c->getId(), $calvesByRow))
        );

        foreach ($calvesByRow as $index => $calf) {
            $tag = $calf->getIdentification()->getValue();
            $lineage = $lineages[(int) $calf->getId()] ?? null;

            if ($lineage === null) {
                $errorsByRow[$index][] = $this->error('NO_LINEAGE', "La caravana '{$tag}' no tiene registro de nacimiento.");
                continue;
            }

            if (!$lineage->isNursing()) {
                $errorsByRow[$index][] = $this->error('ALREADY_WEANED', "La cría '{$tag}' ya está destetada.");
            }

            $birthDate = substr($lineage->getBirthDate(), 0, 10);
            if ($birthDate !== '' && $weaningDate < $birthDate) {
                $errorsByRow[$index][] = $this->error('WEANING_BEFORE_BIRTH', "La fecha de destete es anterior al nacimiento de '{$tag}' ({$birthDate}).");
            }
        }

        $rowErrors = [];
        ksort($errorsByRow);
        foreach ($errorsByRow as $index => $errors) {
            $rowErrors[] = [
                'row_index' => $index,
                'caravana' => $dto->rows[$index]['caravana'],
                'errors' => $errors,
            ];
        }

        if (!empty($headerErrors) || !empty($rowErrors)) {
            throw new Dest01ValidationException($headerErrors, $rowErrors);
        }

        // 4. Clean sheet: delegate persistence to the bulk weaning use case.
        $notes = $this->headerNotes($dto);
        $created = $targetBatch === null;

        $weanings = [];
        foreach ($calvesByRow as $index => $calf) {
            $row = $dto->rows[$index];
            $weanings[] = new WeanCaravanDTO(
                caravanId: (int) $calf->getId(),
                targetBatchId: $targetBatch?->getId() ?? 0,
                weaningDate: $weaningDate,
                weaningWeight: $row['peso'],
                notes: $row['observations'] !== null ? "{$notes} {$row['observations']}" : $notes
            );
        }

        $newBatch = null;
        if ($created) {
            $newBatch = new CreateBatchDTO(
                name: (string) $dto->newBatchName,
                observaciones: $notes,
                activityId: $this->activityRepository->findByCode(self::DEFAULT_ACTIVITY)?->getId(),
                batchTypeId: $weaningBatchTypeId
            );
        }

        ($this->bulkWean)(new BulkWeanDTO($weanings, $newBatch));

        $batch = $created
            ? $this->batchRepository->findActiveByName((string) $dto->newBatchName)
            : $this->batchRepository->findById((int) $targetBatch->getId());

        if ($batch === null) {
            throw new DomainException('No se encontró el lote de destete después de registrar el destete.');
        }

        return [
            'batch' => $batch,
            'created' => $created,
            ...$this->summary($dto, $calvesByRow),
        ];
    }

    /**
     * @param array<int, CaravanEntity> $calvesByRow
     * @return array{calves_count: int, males_count: int, females_count: int, weighed_count: int, average_weight: ?float}
     */
    private function summary(Dest01SubmissionDTO $dto, array $calvesByRow): array
    {
        $males = 0;
        $females = 0;
        $weights = [];

        foreach ($calvesByRow as $index => $calf) {
            match ($calf->getSex()) {
                AnimalSex::MALE => $males++,
                AnimalSex::FEMALE => $females++,
                default => null,
            };

            if ($dto->rows[$index]['peso'] !== null) {
                $weights[] = $dto->rows[$index]['peso'];
            }
        }

        return [
            'calves_count' => count($calvesByRow),
            'males_count' => $males,
            'females_count' => $females,
            'weighed_count' => count($weights),
            'average_weight' => empty($weights) ? null : round(array_sum($weights) / count($weights), 1),
        ];
    }

    private function headerNotes(Dest01SubmissionDTO $dto): string
    {
        $parts = ['Destete cargado desde planilla DEST-01.'];
        if ($dto->tipoDestete !== null) {
            $parts[] = "Tipo: {$dto->tipoDestete}.";
        }
        if ($dto->loteOrigen !== null) {
            $parts[] = "Lote de origen: {$dto->loteOrigen}.";
        }
        if ($dto->responsable !== null) {
            $parts[] = "Responsable: {$dto->responsable}.";
        }
        if ($dto->observaciones !== null) {
            $parts[] = $dto->observaciones;
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{code: string, message: string}
     */
    private function error(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    private function headerError(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }
}
