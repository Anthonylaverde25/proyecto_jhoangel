<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\EntryOrderAnimalEntity;
use App\Core\Entities\EntryOrderBreedEntity;
use App\Core\Entities\EntryOrderDteEntity;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Entities\TransferOrderHistoryEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\SexComposition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An entry order with its troop and DTEs and — unless `summary()` is called, as in the list — the
 * caravans of each DTE and the history.
 *
 * @property-read EntryOrderEntity $resource
 */
class EntryOrderResource extends JsonResource
{
    private bool $detailed = true;

    public function summary(): self
    {
        $this->detailed = false;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $troop = $order->getTroop();
        $status = $order->getStatus();

        $data = [
            'id' => $order->getId(),
            'code' => $order->getCode(),
            'number' => $order->getNumber(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'is_open' => $status->isOpen(),
            'is_editable' => $status->isEditable(),
            'accepts_dte' => $status->acceptsDte(),
            'kind' => $order->getKind()->value,
            'kind_label' => $order->getKind()->label(),
            'provider' => ['id' => $troop->providerId, 'name' => $order->name('provider'), 'cuit' => $order->name('provider_cuit')],
            'farm' => ['id' => $troop->farmId, 'name' => $order->name('farm'), 'renspa' => $order->name('farm_renspa')],
            'auction_number' => $troop->auctionNumber,
            'batch' => $order->getBatchId() !== null ? ['id' => $order->getBatchId(), 'name' => $order->name('batch')] : null,
            'batch_name' => $order->getBatchName(),
            'batch_name_mode' => $order->getBatchNameMode()->value,
            'head_count' => $troop->headCount,
            'category' => ['id' => $troop->categoryId, 'name' => $order->name('category'), 'sex' => $order->name('category_sex')],
            'sex_composition' => $troop->sexComposition->value,
            'sex_composition_label' => $troop->sexComposition->label(),
            'male_count' => $troop->maleCount,
            'female_count' => $troop->femaleCount,
            'condition' => $troop->condition->value,
            'condition_label' => $troop->condition->label(),
            'age_min_months' => $troop->ageMinMonths,
            'age_max_months' => $troop->ageMaxMonths,
            'age_range' => $troop->ageRangeLabel(),
            'knows_to_eat' => $troop->knowsToEat,
            'tick_vaccinated' => $troop->tickVaccinated,
            'shrink_percent' => $troop->shrinkPercent,
            'estimated_weight' => $troop->estimatedWeight,
            'min_weight' => $troop->minWeight,
            'max_weight' => $troop->maxWeight,
            'purchase_date' => $troop->purchaseDate,
            'responsable' => $troop->responsable,
            'observations' => $troop->observations,
            'breeds' => array_map(fn (EntryOrderBreedEntity $b) => [
                'id' => $b->getId(),
                'position' => $b->getPosition(),
                'letter' => $b->getLetter(),
                'breed_id' => $b->getBreedId(),
                'breed_name' => $b->getBreedName(),
                'color_id' => $b->getColorId(),
                'color_name' => $b->getColorName(),
                'label' => $b->getLabel(),
            ], array_values($troop->breedsByPosition())),
            'entered_count' => $order->enteredCount(),
            'pending_count' => $order->pendingCount(),
            'dte_count' => count($order->getDtes()),
            'requested_by' => $order->getRequestedByUserId() !== null
                ? ['id' => $order->getRequestedByUserId(), 'name' => $order->name('requested_by')]
                : null,
            'confirmed_at' => $order->getConfirmedAt()?->format(DATE_ATOM),
            'printed_at' => $order->getPrintedAt()?->format(DATE_ATOM),
            'first_dte_at' => $order->getFirstDteAt()?->format(DATE_ATOM),
            'closed_at' => $order->getClosedAt()?->format(DATE_ATOM),
            'closing_reason' => $order->getClosingReason(),
            'created_at' => $order->getCreatedAt()?->format(DATE_ATOM),
        ];

        $data['dtes'] = array_map(fn (EntryOrderDteEntity $d) => [
            'id' => $d->getId(),
            'dte_number' => $d->getDteNumber(),
            'dte_date' => $d->getDteDate(),
            'entered_at' => $d->getEnteredAt(),
            'head_count' => $d->getHeadCount(),
            'observations' => $d->getObservations(),
            'loaded_by' => $d->getLoadedByUserId() !== null ? ['id' => $d->getLoadedByUserId(), 'name' => $d->getLoadedByUserName()] : null,
            'created_at' => $d->getCreatedAt()?->format(DATE_ATOM),
            ...($this->detailed ? ['animals' => array_map(fn (EntryOrderAnimalEntity $a) => [
                'id' => $a->getId(),
                'caravan_id' => $a->getCaravanId(),
                'identification' => $a->getIdentification(),
                'sex' => $a->getSex(),
                'breed_position' => $a->getBreedPosition(),
                'breed_letter' => $a->getBreedPosition() !== null ? chr(64 + $a->getBreedPosition()) : null,
                'entry_weight' => $a->getEntryWeight(),
                'caravan_movement_id' => $a->getCaravanMovementId(),
            ], $d->getAnimals())] : []),
        ], $order->getDtes());

        if (!$this->detailed) {
            return $data;
        }

        // Head entered by sex, only meaningful when the caravans were loaded (the detail).
        if ($troop->sexComposition === SexComposition::MIXED) {
            $data['entered_male_count'] = $order->enteredCountBySex(AnimalSex::MALE->value);
            $data['entered_female_count'] = $order->enteredCountBySex(AnimalSex::FEMALE->value);
        }

        $data['history'] = array_map(fn (TransferOrderHistoryEntity $h) => [
            'id' => $h->getId(),
            'from_status' => $h->getFromStatus(),
            'to_status' => $h->getToStatus(),
            'action_user' => $h->getActionUserId() !== null
                ? ['id' => $h->getActionUserId(), 'name' => $h->getActionUserName()]
                : null,
            'reason' => $h->getReason(),
            'metadata' => $h->getMetadata(),
            'created_at' => $h->getCreatedAt()?->format(DATE_ATOM),
        ], $order->getHistory());

        return $data;
    }
}
