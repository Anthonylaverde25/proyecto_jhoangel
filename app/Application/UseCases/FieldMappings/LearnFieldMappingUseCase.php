<?php

declare(strict_types=1);

namespace App\Application\UseCases\FieldMappings;

use App\Core\Interfaces\IFieldMappingResolver;

final class LearnFieldMappingUseCase
{
    public function __construct(
        private readonly IFieldMappingResolver $resolver
    ) {
    }

    /**
     * Learn a new field alias from manual user assignment.
     * Persists the mapping so future resolutions recognize this alias automatically.
     *
     * @param string $aliasName   The alias header name from the document
     * @param string $targetField The actual database field name
     * @param string $targetModel Target model name (e.g. 'caravans')
     * @return void
     */
    public function __invoke(string $aliasName, string $targetField, string $targetModel): void
    {
        $this->resolver->learn($aliasName, $targetField, $targetModel);
    }
}
