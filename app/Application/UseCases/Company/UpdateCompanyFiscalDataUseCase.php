<?php

declare(strict_types=1);

namespace App\Application\UseCases\Company;

use App\Core\Entities\CompanyEntity;
use App\Core\Interfaces\ICompanyRepository;
use App\Core\ValueObjects\Cuit;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Sets or clears the company's CUIT. When set, it must be a valid CUIT: documents are checked
 * against it, so a mistyped one would raise false warnings on every invoice.
 */
final class UpdateCompanyFiscalDataUseCase
{
    public function __construct(
        private readonly ICompanyRepository $companies,
    ) {
    }

    public function __invoke(int $companyId, ?string $cuit): CompanyEntity
    {
        if ($this->companies->findById($companyId) === null) {
            throw new ModelNotFoundException("Company {$companyId} not found.");
        }

        $this->companies->updateCuit($companyId, $cuit === null ? null : Cuit::requireValid($cuit)->value());

        return $this->companies->findById($companyId);
    }
}
