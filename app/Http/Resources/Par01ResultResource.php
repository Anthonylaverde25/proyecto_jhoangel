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
            'abortion_count' => $this->resource['abortion_count'],
            'males_count' => $this->resource['males_count'],
            'females_count' => $this->resource['females_count'],
            'unplanned_count' => $this->resource['unplanned_count'],
            'calves' => $this->resource['calves'],
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

        if ($result['abortion_count'] > 0) {
            $parts[] = "{$result['abortion_count']} aborto(s)";
        }

        $sentence = 'Parición registrada: ' . implode(', ', $parts) . '.';
        $order = $result['birth_order'] ?? null;

        return $order !== null ? "{$sentence} Orden {$order['code']}: {$order['status_label']}." : $sentence;
    }
}
