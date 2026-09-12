<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\DiagnosticProtocolEntity;

interface IDiagnosticProtocolRepository
{
    /**
     * @param array<string, mixed> $filters
     * @return array<DiagnosticProtocolEntity>
     */
    public function findAll(int $companyId, array $filters = []): array;

    public function findById(int $id, int $companyId): ?DiagnosticProtocolEntity;

    public function findByProtocolNumber(string $protocolNumber, int $companyId): ?DiagnosticProtocolEntity;

    public function existsByProtocolNumber(string $protocolNumber, int $companyId): bool;

    /**
     * ADR-13: extraction acts still waiting for the professional's signature.
     *
     * @return array<DiagnosticProtocolEntity>
     */
    public function findActsPendingSignature(int $veterinarianId, int $companyId): array;

    /**
     * ADR-11: signed acts whose tubes are still awaiting a laboratory report.
     *
     * @return array<DiagnosticProtocolEntity>
     */
    public function findActsPendingLabReport(int $veterinarianId, int $companyId): array;

    /**
     * ADR-14: batches the professional actually worked on, derived from their own acts.
     *
     * @return list<int>
     */
    public function findBatchIdsWithActsFor(int $veterinarianId, int $companyId): array;

    /**
     * §11.6 reformulated: acts whose tubes were dispatched more than `$overdueDays` ago and
     * still have no result. A consultable filter, never an automatic alert.
     *
     * @return array<DiagnosticProtocolEntity>
     */
    public function findActsShippedWithoutReport(int $companyId, int $overdueDays): array;

    /**
     * The laboratory report already attached to an extraction act, if any.
     */
    public function findLabReportForAct(int $actId, int $companyId): ?DiagnosticProtocolEntity;

    /**
     * Every live report on an act, newest first: fractioned work yields more than one.
     *
     * @return array<DiagnosticProtocolEntity>
     */
    public function findLabReportsForAct(int $actId, int $companyId): array;
}
