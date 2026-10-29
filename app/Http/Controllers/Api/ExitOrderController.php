<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\ExitOrders\ExecuteExitOrderDTO;
use App\Application\DTOs\ExitOrders\ExitDteDTO;
use App\Application\DTOs\ExitOrders\IssueExitOrderDTO;
use App\Application\DTOs\ExitOrders\RegisterExitDTO;
use App\Application\Services\ExitBatchResolver;
use App\Application\UseCases\ExitOrders\ExitOrderUseCases;
use App\Core\Entities\BatchEntity;
use App\Core\Entities\ExitOrderEntity;
use App\Core\Enums\ExitOrderKind;
use App\Core\Enums\ExitOrderStatus;
use App\Core\ValueObjects\ExitedAnimalCriteria;
use App\Core\ValueObjects\ExitOrderCriteria;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExitOrders\ExecuteExitOrderRequest;
use App\Http\Requests\ExitOrders\ExitDteRequest;
use App\Http\Requests\ExitOrders\ExitLineDeathRequest;
use App\Http\Requests\ExitOrders\ExitReasonRequest;
use App\Http\Requests\ExitOrders\IssueExitOrderRequest;
use App\Http\Requests\ExitOrders\RegisterExitRequest;
use App\Http\Resources\ExitOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Exit orders of livestock (egreso plan v5, 05 §3). Thin: every rule lives in the use cases, and a
 * broken one answers 422 with its errors by field or row.
 */
final class ExitOrderController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(private readonly ExitOrderUseCases $useCases)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $number = fn (string $key) => is_numeric($request->query($key)) ? (int) $request->query($key) : null;
        $date = fn (string $key) => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query($key)) === 1 ? $request->query($key) : null;

        $orders = ($this->useCases->list)(new ExitOrderCriteria(
            companyId: $this->activeCompanyId(),
            status: ExitOrderStatus::tryFrom(strtoupper((string) $request->query('status'))),
            kind: ExitOrderKind::tryFrom(strtoupper((string) $request->query('kind'))),
            withoutDte: $request->boolean('without_dte'),
            buyerPartnerId: $number('buyer_partner_id'),
            from: $date('from'),
            to: $date('to'),
            activityId: $number('activity_id'),
        ));

        return response()->json(array_map(
            fn (ExitOrderEntity $order) => (new ExitOrderResource($order))->summary()->resolve($request),
            $orders
        ));
    }

    public function show(int $id): JsonResponse
    {
        return $this->order(($this->useCases->get)($id, $this->activeCompanyId()));
    }

    public function byCode(string $code): JsonResponse
    {
        return $this->order(($this->useCases->byCode)($code, $this->activeCompanyId()));
    }

    /** "Emitir egreso". */
    public function store(IssueExitOrderRequest $request): JsonResponse
    {
        $dto = IssueExitOrderDTO::fromArray($request->validated(), $this->activeCompanyId(), $request->user()?->id);

        return $this->order(($this->useCases->issue)($dto), 201);
    }

    public function update(IssueExitOrderRequest $request, int $id): JsonResponse
    {
        $dto = IssueExitOrderDTO::fromArray($request->validated(), $this->activeCompanyId(), $request->user()?->id);

        return $this->order(($this->useCases->update)($id, $dto));
    }

    /** "Registrar egreso" and "Vender": born executed. */
    public function register(RegisterExitRequest $request): JsonResponse
    {
        $dto = RegisterExitDTO::fromArray($request->validated(), $this->activeCompanyId(), $request->user()?->id);

        return $this->order(($this->useCases->register)($dto), 201);
    }

    public function execute(ExecuteExitOrderRequest $request, int $id): JsonResponse
    {
        $dto = ExecuteExitOrderDTO::fromArray($id, $request->validated(), $this->activeCompanyId(), $request->user()?->id);

        return $this->order(($this->useCases->execute)($dto));
    }

    public function close(ExitReasonRequest $request, int $id): JsonResponse
    {
        return $this->order(($this->useCases->closeIncomplete)($id, (string) $request->validated('reason'), $this->activeCompanyId(), $request->user()?->id));
    }

    public function cancel(ExitReasonRequest $request, int $id): JsonResponse
    {
        return $this->order(($this->useCases->cancel)($id, (string) $request->validated('reason'), $this->activeCompanyId(), $request->user()?->id));
    }

    public function addDte(ExitDteRequest $request, int $id): JsonResponse
    {
        $dto = ExitDteDTO::fromArray($request->validated());

        return $this->order(($this->useCases->addDte)($id, $dto, $this->activeCompanyId(), $request->user()?->id), 201);
    }

    public function removeDte(Request $request, int $id, int $dteId): Response
    {
        ($this->useCases->removeDte)($id, $dteId, $this->activeCompanyId(), $request->user()?->id);

        return response()->noContent();
    }

    /** Death of a pending animal, from its line (01 §5.1). */
    public function deathOnLine(ExitLineDeathRequest $request, int $id, int $caravanId): JsonResponse
    {
        $data = $request->validated();

        return $this->order(($this->useCases->deathOnLine)(
            $id,
            $caravanId,
            (string) $data['death_date'],
            (int) $data['death_cause_id'],
            $data['observations'] ?? null,
            isset($data['death_batch_id']) ? (int) $data['death_batch_id'] : null,
            $this->activeCompanyId(),
            $request->user()?->id
        ));
    }

    /** The calves at foot of the cows of a load, to ask whether they leave with them (P1). */
    public function calvesAtFoot(Request $request): JsonResponse
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) $request->query('caravan_ids', ''))),
            fn (int $id) => $id > 0
        )));

        return response()->json(($this->useCases->calvesAtFoot)($ids));
    }

    /** The animals that already left, one row each, across orders: the base of sales analysis. */
    public function exitedAnimals(Request $request): JsonResponse
    {
        $number = fn (string $key) => is_numeric($request->query($key)) ? (int) $request->query($key) : null;
        $date = fn (string $key) => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query($key)) === 1 ? $request->query($key) : null;

        return response()->json(($this->useCases->exitedAnimals)(new ExitedAnimalCriteria(
            companyId: $this->activeCompanyId(),
            from: $date('from'),
            to: $date('to'),
            exitOrderId: $number('exit_order_id'),
            buyerPartnerId: $number('buyer_partner_id'),
            categoryId: $number('category_id'),
            activityId: $number('activity_id'),
            fromBatchId: $number('from_batch_id'),
        )));
    }

    /** The active exit batches, to choose one when the company has several. */
    public function exitBatches(ExitBatchResolver $resolver): JsonResponse
    {
        return response()->json(array_map(
            fn (BatchEntity $batch) => ['id' => $batch->getId(), 'name' => $batch->getName()],
            $resolver->available()
        ));
    }

    private function order(ExitOrderEntity $order, int $status = 200): JsonResponse
    {
        return response()->json(new ExitOrderResource($order), $status);
    }
}
