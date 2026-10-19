<?php

declare(strict_types=1);

namespace App\Application\UseCases\Warehouses;

use App\Core\Exceptions\WarehouseException;
use App\Core\Interfaces\IWarehouseRepository;

/**
 * Code and name are unique per company (R1). Checked before the unique indexes so the screen gets
 * the field and a readable message instead of a 500.
 */
final class WarehouseUniqueness
{
    public function __construct(
        private readonly IWarehouseRepository $warehouses
    ) {
    }

    public function check(int $companyId, string $code, string $name, ?int $exceptId = null): void
    {
        $errors = [];
        if ($this->warehouses->codeTaken($companyId, $code, $exceptId)) {
            $errors['code'] = ["Ya hay un almacén con el código {$code}."];
        }
        if ($this->warehouses->nameTaken($companyId, $name, $exceptId)) {
            $errors['name'] = ["Ya hay un almacén que se llama {$name}."];
        }
        if ($errors !== []) {
            throw WarehouseException::many($errors);
        }
    }
}
