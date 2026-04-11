<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Core\Interfaces\IFieldMappingResolver;

final class ResolveFieldMappingsUseCase
{
    public function __construct(
        private readonly IFieldMappingResolver $resolver
    ) {
    }

    /**
     * Resolve OCR headers against known field mappings for a target model.
     *
     * @param string[] $headers     Headers detected by OCR
     * @param string   $targetModel Target model name (e.g. 'caravans')
     * @return array{mapped: array<string, string>, unresolved: string[]}
     */
    public function __invoke(array $headers, string $targetModel): array
    {
        $resolved = $this->resolver->resolve($headers, $targetModel);

        $mapped = array_filter($resolved, fn ($value) => $value !== null);
        $unresolved = array_keys(array_filter($resolved, fn ($value) => $value === null));

        return [
            'mapped'     => $mapped,
            'unresolved' => $unresolved,
        ];
    }
}
