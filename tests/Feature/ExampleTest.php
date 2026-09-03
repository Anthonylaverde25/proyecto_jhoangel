<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        \Illuminate\Support\Facades\Artisan::call('migrate');
        $tenant = Tenant::create(['id' => 'test-example-' . uniqid()]);
        $tenant->domains()->create(['domain' => 'test.localhost']);
        tenancy()->initialize($tenant);


        $response = $this->getJson('http://test.localhost/api/batches');

        $response->assertStatus(200);

        tenancy()->end();
    }
}

