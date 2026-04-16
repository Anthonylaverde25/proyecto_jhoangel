<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Interfaces\IWorkdayRepository;
use DateTimeInterface;

final class WorkdayCodeGenerator
{
    public function __construct(
        private readonly IWorkdayRepository $repository
    ) {
    }

    /**
     * Genera el siguiente código secuencial de la jornada para una fecha dada.
     * Ejemplo: WD-20260415-01
     */
    public function generateForDate(DateTimeInterface $date): string
    {
        $prefix = 'WD-' . $date->format('Ymd') . '-';
        $lastCode = $this->repository->getLastCodeForDate($date);

        if ($lastCode === null) {
            return $prefix . '01';
        }

        // Si lastCode es "WD-20260415-03", extraemos "03"
        $parts = explode('-', $lastCode);
        $sequence = (int) end($parts);
        $nextSequence = str_pad((string)($sequence + 1), 2, '0', STR_PAD_LEFT);

        return $prefix . $nextSequence;
    }
}
