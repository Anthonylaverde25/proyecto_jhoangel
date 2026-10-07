<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Interfaces\ICompanyContext;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Enums\TransferOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\BirthOrder;
use App\Models\EntryOrder;
use App\Models\TransferOrder;
use App\Models\WeaningOrder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OperationalKpiController extends Controller
{
    public function __construct(
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $this->companyContext->getCompanyId();

        // 1. Entry Orders (ING-02)
        $entryPendingStatuses = [
            EntryOrderStatus::AWAITING_DTE->value,
            EntryOrderStatus::IN_TRANSIT->value,
            // Received by count, its caravans still to write: not finished either.
            EntryOrderStatus::RECEIVED->value,
            EntryOrderStatus::DRAFT->value,
        ];

        $entryOrdersQuery = EntryOrder::query()
            ->where('company_id', $companyId)
            ->whereIn('status', $entryPendingStatuses);

        $entryStatusCounts = (clone $entryOrdersQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $entryPlannedHeads = (int) (clone $entryOrdersQuery)->sum('head_count');

        $entryRecentItems = (clone $entryOrdersQuery)
            ->with(['provider:id,name', 'batch:id,name'])
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(function (EntryOrder $order): array {
                $statusEnum = EntryOrderStatus::tryFrom($order->status);
                $heads = $order->head_count ?? (($order->male_count ?? 0) + ($order->female_count ?? 0));

                return [
                    'id' => $order->id,
                    'code' => $order->code,
                    'status' => $order->status,
                    'status_label' => $statusEnum?->label() ?? $order->status,
                    'planned_heads' => (int) $heads,
                    'date' => $order->purchase_date?->format('Y-m-d') ?? $order->created_at?->format('Y-m-d'),
                    'provider_name' => $order->provider?->name,
                    'batch_name' => $order->batch?->name ?? $order->batch_name,
                ];
            })
            ->all();

        $entryTotalPending = array_sum($entryStatusCounts);

        // 2. Birth Orders (PAR-01)
        $orderPendingStatuses = [
            TransferOrderStatus::ISSUED->value,
            TransferOrderStatus::PARTIAL->value,
            TransferOrderStatus::DRAFT->value,
        ];

        $birthOrdersQuery = BirthOrder::query()
            ->where('company_id', $companyId)
            ->whereIn('status', $orderPendingStatuses);

        $birthStatusCounts = (clone $birthOrdersQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $birthPlannedHeads = (int) (clone $birthOrdersQuery)->sum('planned_head_count');

        $birthRecentItems = (clone $birthOrdersQuery)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(function (BirthOrder $order): array {
                $statusEnum = TransferOrderStatus::tryFrom($order->status);

                return [
                    'id' => $order->id,
                    'code' => $order->code,
                    'status' => $order->status,
                    'status_label' => $statusEnum?->label() ?? $order->status,
                    'planned_heads' => (int) ($order->planned_head_count ?? 0),
                    'date' => $order->period_start?->format('Y-m-d') ?? $order->created_at?->format('Y-m-d'),
                    'period_start' => $order->period_start?->format('Y-m-d'),
                    'period_end' => $order->period_end?->format('Y-m-d'),
                ];
            })
            ->all();

        $birthTotalPending = array_sum($birthStatusCounts);

        // 3. Transfer Orders (CACT-01)
        $transferOrdersQuery = TransferOrder::query()
            ->where('company_id', $companyId)
            ->whereIn('status', $orderPendingStatuses);

        $transferStatusCounts = (clone $transferOrdersQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $transferPlannedHeads = (int) (clone $transferOrdersQuery)->sum('planned_head_count');

        $transferRecentItems = (clone $transferOrdersQuery)
            ->with(['sourceBatch:id,name'])
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(function (TransferOrder $order): array {
                $statusEnum = TransferOrderStatus::tryFrom($order->status);

                return [
                    'id' => $order->id,
                    'code' => $order->code,
                    'status' => $order->status,
                    'status_label' => $statusEnum?->label() ?? $order->status,
                    'planned_heads' => (int) ($order->planned_head_count ?? 0),
                    'date' => $order->movement_date?->format('Y-m-d') ?? $order->created_at?->format('Y-m-d'),
                    'source_batch_name' => $order->sourceBatch?->name,
                ];
            })
            ->all();

        $transferTotalPending = array_sum($transferStatusCounts);

        // 4. Weaning Orders (DEST-01)
        $weaningOrdersQuery = WeaningOrder::query()
            ->where('company_id', $companyId)
            ->whereIn('status', $orderPendingStatuses);

        $weaningStatusCounts = (clone $weaningOrdersQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $weaningPlannedHeads = (int) (clone $weaningOrdersQuery)->sum('planned_head_count');

        $weaningRecentItems = (clone $weaningOrdersQuery)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(function (WeaningOrder $order): array {
                $statusEnum = TransferOrderStatus::tryFrom($order->status);

                return [
                    'id' => $order->id,
                    'code' => $order->code,
                    'status' => $order->status,
                    'status_label' => $statusEnum?->label() ?? $order->status,
                    'planned_heads' => (int) ($order->planned_head_count ?? 0),
                    'date' => $order->weaning_date?->format('Y-m-d') ?? $order->created_at?->format('Y-m-d'),
                    'weaning_type' => $order->weaning_type,
                ];
            })
            ->all();

        $weaningTotalPending = array_sum($weaningStatusCounts);

        $grandTotalPending = $entryTotalPending + $birthTotalPending + $transferTotalPending + $weaningTotalPending;

        return response()->json([
            'summary' => [
                'total_pending_documents' => $grandTotalPending,
                'last_updated_at' => Carbon::now()->toIso8601String(),
            ],
            'categories' => [
                'entry_orders' => [
                    'key' => 'entry_orders',
                    'template_code' => 'ING-02',
                    'title' => 'Entradas de Hacienda',
                    'subtitle' => 'Compras e ingresos a campo',
                    'icon' => 'truck',
                    'color' => '#059669', // Emerald
                    'total_pending' => $entryTotalPending,
                    'total_planned_heads' => $entryPlannedHeads,
                    'breakdown' => [
                        'awaiting_dte' => $entryStatusCounts[EntryOrderStatus::AWAITING_DTE->value] ?? 0,
                        'in_transit' => $entryStatusCounts[EntryOrderStatus::IN_TRANSIT->value] ?? 0,
                        'received' => $entryStatusCounts[EntryOrderStatus::RECEIVED->value] ?? 0,
                        'draft' => $entryStatusCounts[EntryOrderStatus::DRAFT->value] ?? 0,
                    ],
                    'items' => $entryRecentItems,
                ],
                'birth_orders' => [
                    'key' => 'birth_orders',
                    'template_code' => 'PAR-01',
                    'title' => 'Parición y Nacimientos',
                    'subtitle' => 'Temporada y registro de partos',
                    'icon' => 'baby-carriage',
                    'color' => '#D97706', // Amber
                    'total_pending' => $birthTotalPending,
                    'total_planned_heads' => $birthPlannedHeads,
                    'breakdown' => [
                        'issued' => $birthStatusCounts[TransferOrderStatus::ISSUED->value] ?? 0,
                        'partial' => $birthStatusCounts[TransferOrderStatus::PARTIAL->value] ?? 0,
                        'draft' => $birthStatusCounts[TransferOrderStatus::DRAFT->value] ?? 0,
                    ],
                    'items' => $birthRecentItems,
                ],
                'transfer_orders' => [
                    'key' => 'transfer_orders',
                    'template_code' => 'CACT-01',
                    'title' => 'Cambio de Destino / Traslado',
                    'subtitle' => 'Movimientos entre lotes y potreros',
                    'icon' => 'swap-horizontal',
                    'color' => '#2563EB', // Blue
                    'total_pending' => $transferTotalPending,
                    'total_planned_heads' => $transferPlannedHeads,
                    'breakdown' => [
                        'issued' => $transferStatusCounts[TransferOrderStatus::ISSUED->value] ?? 0,
                        'partial' => $transferStatusCounts[TransferOrderStatus::PARTIAL->value] ?? 0,
                        'draft' => $transferStatusCounts[TransferOrderStatus::DRAFT->value] ?? 0,
                    ],
                    'items' => $transferRecentItems,
                ],
                'weaning_orders' => [
                    'key' => 'weaning_orders',
                    'template_code' => 'DEST-01',
                    'title' => 'Destetes Planificados',
                    'subtitle' => 'Separación y pesada de terneros',
                    'icon' => 'cow',
                    'color' => '#7C3AED', // Violet
                    'total_pending' => $weaningTotalPending,
                    'total_planned_heads' => $weaningPlannedHeads,
                    'breakdown' => [
                        'issued' => $weaningStatusCounts[TransferOrderStatus::ISSUED->value] ?? 0,
                        'partial' => $weaningStatusCounts[TransferOrderStatus::PARTIAL->value] ?? 0,
                        'draft' => $weaningStatusCounts[TransferOrderStatus::DRAFT->value] ?? 0,
                    ],
                    'items' => $weaningRecentItems,
                ],
            ],
        ]);
    }
}
