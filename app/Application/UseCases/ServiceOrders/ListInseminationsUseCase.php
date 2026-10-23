<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Interfaces\IServiceOrderInseminationRepository;
use App\Core\ValueObjects\InseminationFilters;

/**
 * Which animals were inseminated, across every AI order (semen S-B): read by the assistant and the
 * MCP. One row per insemination; the totals count the distinct females too.
 */
final class ListInseminationsUseCase
{
    public function __construct(
        private readonly IServiceOrderInseminationRepository $inseminations,
    ) {
    }

    /** @return array{data: list<array<string, mixed>>, total: int, females: int, straws: int, page: int, per_page: int} */
    public function __invoke(InseminationFilters $filters, int $companyId): array
    {
        return $this->inseminations->search($filters, $companyId) + ['page' => $filters->page, 'per_page' => $filters->perPage];
    }
}
