<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanitary evidence must never reach the real storage directory during a test run.
        // Creating a tenant triggers stancl/tenancy's seeding pipeline, and DiagnosticProtocolSeeder
        // writes a demo attachment; without this the suite littered storage/app/tenants with a file
        // per ephemeral tenant.
        Storage::fake('tenant');
    }
}
