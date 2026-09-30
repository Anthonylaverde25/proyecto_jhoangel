<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Interfaces\ICaravanRegistrationSubmissionRepository;
use App\Models\CaravanRegistrationSubmission;

class EloquentCaravanRegistrationSubmissionRepository implements ICaravanRegistrationSubmissionRepository
{
    public function findRegistered(int $companyId, string $submissionId): ?array
    {
        $row = CaravanRegistrationSubmission::where('company_id', $companyId)
            ->where('submission_id', $submissionId)
            ->first();

        return $row?->registered;
    }

    public function record(int $companyId, string $submissionId, array $registered): void
    {
        CaravanRegistrationSubmission::create([
            'company_id' => $companyId,
            'submission_id' => $submissionId,
            'registered_count' => count($registered),
            'registered' => $registered,
        ]);
    }
}
