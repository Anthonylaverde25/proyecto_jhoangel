<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\BatchEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a DEST-01 weaning answers, whether it came from a scanned sheet or from executing a weaning
 * order on the screen: the same weaning, the same shape.
 *
 * `batch_*` name the first weaning batch, which with one destination for everybody is the only one;
 * `destinations` lists every batch that received calves.
 *
 * @property-read array<string, mixed> $resource
 */
class Dest01ResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BatchEntity $batch */
        $batch = $this->resource['batch'];

        return [
            'batch_id' => $batch->getId(),
            'batch_name' => $batch->getName(),
            'batch_created' => $this->resource['created'],
            'destinations' => $this->resource['destinations'],
            'calves_count' => $this->resource['calves_count'],
            'males_count' => $this->resource['males_count'],
            'females_count' => $this->resource['females_count'],
            'weighed_count' => $this->resource['weighed_count'],
            'average_weight' => $this->resource['average_weight'],
            'warnings' => $this->resource['warnings'],
            'weaning_order' => $this->resource['weaning_order'],
        ];
    }

    /**
     * The sentence the screen shows once the weaning went through.
     *
     * @param array<string, mixed> $result
     */
    public static function message(array $result): string
    {
        $destinations = $result['destinations'];

        if (count($destinations) === 1) {
            $action = $result['created'] ? 'creado' : 'actualizado';
            $sentence = "Destete registrado: {$result['calves_count']} crías en el lote '{$destinations[0]['batch_name']}' ({$action}).";
        } else {
            $sentence = "Destete registrado: {$result['calves_count']} crías en " . count($destinations) . ' lotes de destete.';
        }

        $order = $result['weaning_order'] ?? null;

        return $order !== null ? "{$sentence} Orden {$order['code']}: {$order['status_label']}." : $sentence;
    }
}
