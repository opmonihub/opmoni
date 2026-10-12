<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_health_check_returns_a_successful_response(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }

    public function test_the_backend_has_no_frontend_page(): void
    {
        $this->get('/')->assertNotFound();
    }

    public function test_unknown_api_routes_return_json(): void
    {
        $this->get('/api/missing')->assertNotFound()->assertJsonStructure(['message']);
    }
}
