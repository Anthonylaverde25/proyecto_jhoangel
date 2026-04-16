<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\WorkdayEntity;

interface IWorkdayRepository
{
    public function save(WorkdayEntity $workday): WorkdayEntity;
    
    public function findById(int $id): ?WorkdayEntity;
    
    public function findByCode(string $code): ?WorkdayEntity;

    /**
     * Devuelve el último código (alfabéticamente) generado para una fecha dada.
     * Útil para calcular el siguiente secuencial.
     */
    public function getLastCodeForDate(\DateTimeInterface $date): ?string;

    /**
     * Vincula un arreglo de IDs de Caravanas a la jornada actual (Muchos a Muchos).
     * Garantiza que no se generen registros duplicados en la pivot.
     */
    public function attachCaravans(WorkdayEntity $workday, array $caravanIds): void;
}
