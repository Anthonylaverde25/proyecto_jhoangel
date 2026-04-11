<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

interface IFieldMappingResolver
{
    /**
     * Resolve OCR-detected headers to actual database field names.
     *
     * @param string[] $headers     Headers extracted from the document
     * @param string   $targetModel Target model/table name (e.g. 'caravans')
     * @return array<string, string|null> Map of [original_header => db_field_name|null]
     */
    public function resolve(array $headers, string $targetModel): array;

    /**
     * Register a new alias learned from manual user assignment.
     *
     * @param string $aliasName   The alias header name
     * @param string $targetField The actual database field name
     * @param string $targetModel Target model/table name
     * @return void
     */
    public function learn(string $aliasName, string $targetField, string $targetModel): void;
}
