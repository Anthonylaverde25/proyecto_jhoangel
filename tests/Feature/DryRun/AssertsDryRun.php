<?php

declare(strict_types=1);

namespace Tests\Feature\DryRun;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * A request sent with `X-Dry-Run: 1` must answer as the real one would and leave every table as it
 * was.
 */
trait AssertsDryRun
{
    /**
     * @param callable(): TestResponse $request
     */
    protected function dryRun(callable $request): TestResponse
    {
        $this->withHeader('X-Dry-Run', '1');

        try {
            return $request();
        } finally {
            $this->flushHeaders();
        }
    }

    /**
     * @param list<string> $tables
     * @return array<string, int>
     */
    protected function tableCounts(array $tables): array
    {
        return array_combine($tables, array_map(fn (string $table) => DB::table($table)->count(), $tables));
    }

    /**
     * @param list<string> $tables
     * @param callable(): TestResponse $request
     */
    protected function assertDryRunLeavesNothing(array $tables, callable $request, int $status): TestResponse
    {
        $before = $this->tableCounts($tables);

        $response = $this->dryRun($request)->assertStatus($status);

        $this->assertTrue($response->json('dry_run'));
        $this->assertSame('1', $response->headers->get('X-Dry-Run'));
        $this->assertSame($before, $this->tableCounts($tables), 'A dry run must not write anything.');

        return $response;
    }
}
