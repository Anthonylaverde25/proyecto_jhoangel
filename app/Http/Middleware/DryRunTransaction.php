<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * "What would happen?": with `X-Dry-Run: 1` the request runs exactly as it would — same use case,
 * same rules, same errors — inside a transaction that is always rolled back. The answer keeps its
 * status (200/201 with what would be saved, 422 with the problems) and says `"dry_run": true`.
 *
 * It lets a client review a scanned sheet against the real rules before saving it, without a copy
 * of those rules of its own. Only for routes whose work is all in the tenant database: anything
 * outside it (mail, files, queues) would not be undone.
 */
final class DryRunTransaction
{
    public const HEADER = 'X-Dry-Run';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header(self::HEADER) !== '1') {
            return $next($request);
        }

        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $connection->beginTransaction();

        try {
            // An exception thrown by the controller comes back here already rendered as a response.
            $response = $next($request);
        } finally {
            while ($connection->transactionLevel() > $level) {
                $connection->rollBack();
            }
        }

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            if (is_array($data) && !array_is_list($data)) {
                $response->setData([...$data, 'dry_run' => true]);
            }
        }

        $response->headers->set(self::HEADER, '1');

        return $response;
    }
}
