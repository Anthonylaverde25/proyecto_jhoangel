<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Interfaces\IFieldMappingResolver;
use App\Models\FieldMapping;

class EloquentFieldMappingResolver implements IFieldMappingResolver
{
    /**
     * {@inheritdoc}
     */
    public function resolve(array $headers, string $targetModel): array
    {
        $normalizedHeaders = array_map([$this, 'normalize'], $headers);

        // Fetch all mappings for this model in a single query
        $mappings = FieldMapping::forModel($targetModel)
            ->whereIn('alias_name', $normalizedHeaders)
            ->pluck('target_field', 'alias_name')
            ->toArray();

        $result = [];
        foreach ($headers as $header) {
            $normalized = $this->normalize($header);
            $result[$header] = $mappings[$normalized] ?? null;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function learn(string $aliasName, string $targetField, string $targetModel): void
    {
        FieldMapping::updateOrCreate(
            [
                'alias_name'   => $this->normalize($aliasName),
                'target_model' => $targetModel,
            ],
            [
                'target_field' => $targetField,
            ]
        );
    }

    /**
     * Normalize a header string for consistent matching.
     * Converts to lowercase, trims whitespace, replaces spaces with underscores,
     * and removes special characters.
     *
     * @param string $header
     * @return string
     */
    private function normalize(string $header): string
    {
        $normalized = mb_strtolower(trim($header));
        $normalized = preg_replace('/[^a-z0-9áéíóúñü_ ]/u', '', $normalized);
        $normalized = preg_replace('/\s+/', '_', $normalized);

        return $normalized ?: 'unknown';
    }
}
