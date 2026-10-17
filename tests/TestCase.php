<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /** @var list<string> Tenants created during the current test, removed on tearDown. */
    private array $createdTenantIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Sanitary evidence must never reach the real storage directory during a test run.
        // Creating a tenant triggers stancl/tenancy's seeding pipeline, and DiagnosticProtocolSeeder
        // writes a demo attachment; without this the suite littered storage/app/tenants with a file
        // per ephemeral tenant.
        Storage::fake('tenant');

        Tenant::created(function (Tenant $tenant): void {
            $this->createdTenantIds[] = (string) $tenant->getTenantKey();
        });
    }

    protected function tearDown(): void
    {
        $this->removeCreatedTenants();

        parent::tearDown();
    }

    /**
     * Every tenant is a SQLite file under database/ and a folder under storage/. A test that
     * forgot to delete its tenant left both behind on every run, gigabytes after a while.
     */
    private function removeCreatedTenants(): void
    {
        if ($this->createdTenantIds === []) {
            return;
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        foreach ($this->createdTenantIds as $id) {
            try {
                Tenant::find($id)?->delete();
            } catch (\Throwable) {
                // The central schema may already be gone; the files below are removed anyway.
            }

            File::delete([base_path("database/tenant_{$id}"), base_path("database/tenant_{$id}-journal")]);
            File::deleteDirectory(base_path("storage/tenant{$id}"));
        }

        $this->createdTenantIds = [];
    }
}
