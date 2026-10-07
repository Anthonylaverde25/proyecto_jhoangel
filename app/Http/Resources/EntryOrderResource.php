<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\EntryOrderAnimalEntity;
use App\Core\Entities\EntryOrderBreedEntity;
use App\Core\Entities\EntryOrderCategoryEntity;
use App\Core\Entities\EntryOrderDteEntity;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Entities\EntryOrderIncidentEntity;
use App\Core\Entities\EntryOrderReceiptSheetEntity;
use App\Core\Entities\TransferOrderHistoryEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\ArrivalFinding;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Enums\SexComposition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An entry order with its troop and DTEs — head declared, received, in transit and missing — and,
 * unless `summary()` is called, as in the list, the caravans received on each DTE and the history.
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
            'accepts_reception' => $status->acceptsReception() && $order->inTransitCount() > 0,
            'can_cancel' => $status === EntryOrderStatus::DRAFT
                || ($status === EntryOrderStatus::AWAITING_DTE && $order->getDtes() === []),
            // A DTE loaded wrong is corrected while its animals may still arrive.
            'can_correct_dtes' => $status->acceptsReception(),
            'can_close_incomplete' => ($status === EntryOrderStatus::AWAITING_DTE && $order->getDtes() !== [])
                || $status === EntryOrderStatus::IN_TRANSIT,
            'kind' => $order->getKind()->value,
            'kind_label' => $order->getKind()->label(),
            'provider' => ['id' => $troop->providerId, 'name' => $order->name('provider'), 'cuit' => $order->name('provider_cuit')],
            'farm' => ['id' => $troop->farmId, 'name' => $order->name('farm'), 'renspa' => $order->name('farm_renspa')],
            'auction_number' => $troop->auctionNumber,
            'batch' => $order->getBatchId() !== null ? ['id' => $order->getBatchId(), 'name' => $order->name('batch')] : null,
            'batch_name' => $order->getBatchName(),
            'batch_name_mode' => $order->getBatchNameMode()->value,
            'head_count' => $troop->headCount,
            'categories' => array_map(fn (EntryOrderCategoryEntity $c) => [
                'id' => $c->getId(),
                'position' => $c->getPosition(),
                'category_id' => $c->getCategoryId(),
                'name' => $c->getCategoryName(),
                'sex' => $c->getCategorySex(),
                'head_count' => $c->getHeadCount(),
                'label' => $c->getLabel(),
            ], array_values($troop->categoriesByPosition())),
            // Some animal's sex leaves more than one category possible: each line says which (CAT).
            'needs_category_per_animal' => $troop->needsCategoryPerAnimal(),
            'sex_composition' => $troop->sexComposition?->value,
            'sex_composition_label' => $troop->sexComposition?->label(),
            'male_count' => $troop->maleCount,
            'female_count' => $troop->femaleCount,
            'condition' => $troop->condition?->value,
            'condition_label' => $troop->condition?->label(),
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
            'with_dte_count' => $order->withDteCount(),
            'pending_dte_count' => $order->pendingDteCount(),
            'in_transit_count' => $order->inTransitCount(),
            'received_count' => $order->receivedCount(),
            // Received by count, their caravans still to write (on an ING-03 or by hand).
            'uncaravaned_count' => $order->uncaravanedCount(),
            'missing_count' => $order->missingCount(),
            'open_incidents_count' => $order->openIncidentsCount(),
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
            'head_count' => $d->getHeadCount(),
            'received_count' => $d->receivedCount(),
            'caravaned_count' => $d->caravanedCount(),
            'uncaravaned_count' => $d->getUncaravanedHeadCount(),
            // Head an ING-03 of it expects: in transit plus received without caravan.
            'to_identify_count' => $d->toIdentifyCount(),
            'missing_head_count' => $d->getMissingHeadCount(),
            'pending_count' => $d->pendingCount(),
            'excess_count' => $d->excessCount(),
            'accounted_count' => $d->accountedCount(),
            'observations' => $d->getObservations(),
            'loaded_by' => $d->getLoadedByUserId() !== null ? ['id' => $d->getLoadedByUserId(), 'name' => $d->getLoadedByUserName()] : null,
            'created_at' => $d->getCreatedAt()?->format(DATE_ATOM),
            ...($this->detailed ? [
                'animals' => array_map(fn (EntryOrderAnimalEntity $a) => [
                    'id' => $a->getId(),
                    'caravan_id' => $a->getCaravanId(),
                    'identification' => $a->getIdentification(),
                    'sex' => $a->getSex(),
                    'breed_position' => $a->getBreedPosition(),
                    'breed_letter' => $a->getBreedPosition() !== null ? chr(64 + $a->getBreedPosition()) : null,
                    'category_position' => $a->getCategoryPosition(),
                    'entry_weight' => $a->getEntryWeight(),
                    'caravan_movement_id' => $a->getCaravanMovementId(),
                    'received_at' => $a->getReceivedAt(),
                    'reception_method' => $a->getReceptionMethod()?->value,
                    'arrival_findings' => array_map(fn (ArrivalFinding $f) => $f->value, $a->getArrivalFindings()),
                ], $d->getAnimals()),
                // Caravans that came off the truck with each finding (only with the caravans loaded).
                'arrival_findings_count' => self::findingCounts($d),
            ] : []),
        ], $order->getDtes());

        $dtesById = [];
        foreach ($order->getDtes() as $d) {
            $dtesById[$d->getId()] = $d;
        }

        // The ING-03 sheets go in the list too: the tray opens the one still out from its row.
        $data['receipt_sheets'] = array_map(fn (EntryOrderReceiptSheetEntity $r) => [
            'id' => $r->getId(),
            'number' => $r->getNumber(),
            'label' => $r->label(),
            'dte_id' => $r->getDteId(),
            'dte_number' => $r->getDteNumber(),
            'status' => $r->getStatus()->value,
            'status_label' => $r->getStatus()->label(),
            'is_active' => $r->getStatus()->isActive(),
            'weighing_mode' => $r->getWeighingMode()->value,
            'weighing_mode_label' => $r->getWeighingMode()->label(),
            'reference_mode' => $r->getReferenceMode()->value,
            'reference_mode_label' => $r->getReferenceMode()->label(),
            'dte_head_count' => $r->getDteHeadCount(),
            'expected_head_count' => $r->getExpectedHeadCount(),
            'row_count' => $r->getRowCount(),
            // Issued for another head count than the DTE declares now: it was corrected.
            'outdated' => isset($dtesById[$r->getDteId()]) && $r->isOutdatedFor($dtesById[$r->getDteId()]),
            'page_count' => $r->getPageCount(),
            'processed_pages' => $r->getProcessedPages(),
            'missing_pages' => $r->missingPages(),
            'issued_by' => $r->getIssuedByUserId() !== null ? ['id' => $r->getIssuedByUserId(), 'name' => $r->getIssuedByUserName()] : null,
            'printed_at' => $r->getPrintedAt()?->format(DATE_ATOM),
            'processed_at' => $r->getProcessedAt()?->format(DATE_ATOM),
            'replaced_at' => $r->getReplacedAt()?->format(DATE_ATOM),
            'created_at' => $r->getCreatedAt()?->format(DATE_ATOM),
        ], $order->getReceiptSheets());

        if (!$this->detailed) {
            return $data;
        }

        // Head received by sex, only meaningful when the caravans were loaded (the detail).
        if ($troop->sexComposition === SexComposition::MIXED) {
            $data['received_male_count'] = $order->receivedCountBySex(AnimalSex::MALE->value);
            $data['received_female_count'] = $order->receivedCountBySex(AnimalSex::FEMALE->value);
        }

        // Head received per category line, only meaningful with the caravans loaded (the detail).
        if (count($troop->categories) > 1) {
            $data['received_by_category'] = array_map(fn (EntryOrderCategoryEntity $c) => [
                'position' => $c->getPosition(),
                'received_count' => $order->receivedCountByCategory($c->getPosition()),
            ], array_values($troop->categoriesByPosition()));
        }

        $data['incidents'] = array_map(fn (EntryOrderIncidentEntity $i) => [
            'id' => $i->getId(),
            'type' => $i->getType()->value,
            'type_label' => $i->getType()->label(),
            'detail' => $i->getDetail(),
            'metadata' => $i->getMetadata(),
            'status' => $i->getStatus()->value,
            'status_label' => $i->getStatus()->label(),
            'dte_id' => $i->getDteId(),
            'dte_number' => $i->getDteNumber(),
            'resolution' => $i->getResolution(),
            'raised_by' => $i->getRaisedByUserId() !== null ? ['id' => $i->getRaisedByUserId(), 'name' => $i->getRaisedByUserName()] : null,
            'resolved_by' => $i->getResolvedByUserId() !== null ? ['id' => $i->getResolvedByUserId(), 'name' => $i->getResolvedByUserName()] : null,
            'resolved_at' => $i->getResolvedAt()?->format(DATE_ATOM),
            'created_at' => $i->getCreatedAt()?->format(DATE_ATOM),
        ], $order->getIncidents());

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

    /**
     * @return array<string, int> finding → caravans of the DTE that came with it
     */
    private static function findingCounts(EntryOrderDteEntity $dte): array
    {
        $counts = array_fill_keys(array_map(fn (ArrivalFinding $f) => $f->value, ArrivalFinding::cases()), 0);

        foreach ($dte->getAnimals() as $animal) {
            foreach ($animal->getArrivalFindings() as $finding) {
                $counts[$finding->value]++;
            }
        }

        return $counts;
    }
}
