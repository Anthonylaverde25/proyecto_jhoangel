<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Laboratory assay performed on the sample. A clinical exam is deliberately absent:
 * it is not a laboratory sample and belongs in `bull_health_evaluations`.
 */
enum SampleType: string
{
    case PREPUCE_SCRAPE = 'PREPUCE_SCRAPE';
    case BLOOD_SEROLOGY = 'BLOOD_SEROLOGY';
    case SEMEN_CULTURE = 'SEMEN_CULTURE';
    case TUBERCULIN_TEST = 'TUBERCULIN_TEST';
}
