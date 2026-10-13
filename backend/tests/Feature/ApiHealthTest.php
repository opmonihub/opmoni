<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiHealthTest extends TestCase
{
    public function test_health_endpoint_returns_the_backend_identity_without_caching(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'service' => 'backend'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
