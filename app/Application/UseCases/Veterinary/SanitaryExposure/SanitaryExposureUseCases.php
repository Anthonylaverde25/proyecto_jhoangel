<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary\SanitaryExposure;

final class SanitaryExposureUseCases
{
    public function __construct(
        public readonly ListSanitaryExposureAlertsUseCase $list,
        public readonly GetSanitaryExposureAlertUseCase $show,
        public readonly ReviewExposedFemalesUseCase $review,
        public readonly ResolveSanitaryExposureAlertUseCase $resolve,
        public readonly ListOpenExposuresForFemalesUseCase $openForFemales,
    ) {
    }
}
