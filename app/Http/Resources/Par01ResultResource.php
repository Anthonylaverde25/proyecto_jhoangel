<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a PAR-01 round answers, whether it came from a scanned sheet, from executing a birth order
 * on the screen or from registering calvings: the same round, the same shape.
 *
 * @property-read array<string, mixed> $resource
 */
class Par01ResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'resolved_count' => $this->resource['resolved_count'],
            'live_count' => $this->resource['live_count'],
            'stillborn_count' => $this->resource['stillborn_count'],
            'perinatal_death_count' => $this->resource['perinatal_death_count'],
            'overdue_new_count' => $this->resource['overdue_new_count'],
            'overdue_resolved_count' => $this->resource['overdue_resolved_count'],
            'overdue_open_count' => $this->resource['overdue_open_count'],
            'already_registered_count' => $this->resource['already_registered_count'],
            'differs_count' => $this->resource['differs_count'],
            'males_count' => $this->resource['males_count'],
            'females_count' => $this->resource['females_count'],
            'unplanned_count' => $this->resource['unplanned_count'],
            'calves' => $this->resource['calves'],
            'overdue_new' => $this->resource['overdue_new'],
            'overdue_resolved' => $this->resource['overdue_resolved'],
            'already_registered' => $this->resource['already_registered'],
            'warnings' => $this->resource['warnings'],
            'birth_order' => $this->resource['birth_order'],
        ];
    }

    /**
     * The sentence the screen shows once the round went through.
     *
     * @param array<string, mixed> $result
     */
    public static function message(array $result): string
    {
        $parts = ["{$result['live_count']} parto(s) con cría viva"];

        if ($result['stillborn_count'] > 0) {
            $parts[] = "{$result['stillborn_count']} nacido(s) muerto(s)";
        }

        if ($result['perinatal_death_count'] > 0) {
            $parts[] = "{$result['perinatal_death_count']} muerto(s) al pie";
        }

        if ($result['overdue_new_count'] > 0) {
            $parts[] = "{$result['overdue_new_count']} parto(s) vencido(s) avisado(s)";
        }

        if ($result['already_registered_count'] > 0) {
            $parts[] = "{$result['already_registered_count']} ya registrada(s)";
        }

        $sentence = 'Parición registrada: ' . implode(', ', $parts) . '.';
        $order = $result['birth_order'] ?? null;

        return $order !== null ? "{$sentence} Orden {$order['code']}: {$order['status_label']}." : $sentence;
    }
}
