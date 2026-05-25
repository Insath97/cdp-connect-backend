<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;

class ExternalEmployeesSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.external_api.key' => 'test_api_key_123']);
    }

    public function test_it_rejects_requests_without_api_key()
    {
        $response = $this->getJson('/api/v1/external/employees-summary');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized. Invalid or missing API Key.'
            ]);
    }

    public function test_it_rejects_requests_with_invalid_api_key()
    {
        $response = $this->getJson('/api/v1/external/employees-summary', [
            'X-API-Key' => 'wrong_key'
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized. Invalid or missing API Key.'
            ]);
    }

    public function test_it_accepts_requests_with_valid_api_key_header()
    {
        $response = $this->getJson('/api/v1/external/employees-summary', [
            'X-API-Key' => 'test_api_key_123'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Employees summary retrieved successfully'
            ]);
    }

    public function test_it_accepts_requests_with_valid_api_key_query_param()
    {
        $response = $this->getJson('/api/v1/external/employees-summary?api_key=test_api_key_123');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Employees summary retrieved successfully'
            ]);
    }
}
