<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use Illuminate\Http\UploadedFile;

interface IOCRProvider
{
    /**
     * Analyze a document and extract structured tabular data.
     *
     * @param UploadedFile $file
     * @return array
     */
    public function analyze(UploadedFile $file): array;
}
