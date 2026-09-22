<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExternalCustomerInvestmentsTest extends TestCase
{
    protected string $credixKey = 'a8F2kP9xQ7mL4vR6tN3zY5wC1bH8sD0Kj4R7mX9pL2vQ6tW3nF8cA5zE1yU';
    protected string $otherExternalKey = 'alavaangu_rimas_secret_api_key_123456';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.credix_api.key' => $this->credixKey,
            'services.external_api.key' => $this->otherExternalKey,
        ]);
    }

    public function test_it_rejects_requests_without_api_key()
    {
        $response = $this->getJson('/api/v1/external/customer-investments?id_number=199012345678');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized. Invalid or missing Credix API Key.'
            ]);
    }

    public function test_it_rejects_requests_with_invalid_api_key()
    {
        $response = $this->getJson('/api/v1/external/customer-investments?id_number=199012345678', [
            'X-API-Key' => 'wrong_key'
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized. Invalid or missing Credix API Key.'
            ]);
    }

    public function test_it_rejects_requests_with_other_external_api_key()
    {
        // Must reject calls using the HR/employees EXTERNAL_API_KEY
        $response = $this->getJson('/api/v1/external/customer-investments?id_number=199012345678', [
            'X-API-Key' => $this->otherExternalKey
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized. Invalid or missing Credix API Key.'
            ]);
    }

    public function test_it_validates_missing_id_number_with_x_api_key()
    {
        $response = $this->getJson('/api/v1/external/customer-investments', [
            'X-API-Key' => $this->credixKey
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'The id_number field is required.'
            ]);
    }

    public function test_it_validates_missing_id_number_with_x_credix_key()
    {
        $response = $this->getJson('/api/v1/external/customer-investments', [
            'X-Credix-Key' => $this->credixKey
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'The id_number field is required.'
            ]);
    }

    public function test_it_validates_missing_id_number_with_query_param()
    {
        $response = $this->getJson('/api/v1/external/customer-investments?api_key=' . $this->credixKey);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'The id_number field is required.'
            ]);
    }
}
