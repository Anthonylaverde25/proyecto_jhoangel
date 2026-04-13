<?php

declare(strict_types=1);

namespace App\Infrastructure\OCR;

use App\Core\Interfaces\IOCRProvider;
use Illuminate\Support\Facades\App;

class OCRProviderFactory
{
    /**
     * Resolve the OCR provider implementation.
     *
     * @param string|null $driver
     * @return IOCRProvider
     */
    public static function make(?string $driver = null): IOCRProvider
    {
        $driver = $driver ?? config('services.ocr.driver', 'azure');

        return match ($driver) {
            // 'google' => App::make(GoogleOCRProvider::class),
            'azure'  => App::make(AzureOCRProvider::class),
            default  => App::make(AzureOCRProvider::class),
        };
    }
}
