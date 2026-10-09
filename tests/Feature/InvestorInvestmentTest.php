<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentPayout;
use App\Models\InvestmentProduct;
use App\Models\Province;
use App\Models\Region;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestorInvestmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $agent;
    protected Customer $customer;
    protected Branch $branch;
    protected InvestmentProduct $product;
    protected Investment $investment;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create a customer user
        $this->user = User::create([
            'name'      => 'Test Investor',
            'username'  => 'investor_01',
            'email'     => 'investor@test.com',
            'id_type'   => 'nic',
            'id_number' => '199512345678',
            'password'  => bcrypt('password123'),
            'user_type' => 'customer',
            'is_active' => true,
            'can_login' => true,
        ]);

        // Agent user
        $this->agent = User::create([
            'name'          => 'Agent Kamal',
            'username'      => 'agent_kamal',
            'email'         => 'agent@test.com',
            'employee_code' => 'EMP-007',
            'password'      => bcrypt('password123'),
            'user_type'     => 'hierarchy',
            'is_active'     => true,
            'can_login'     => true,
        ]);

        // 2. Create customer linked to user
        $this->customer = Customer::create([
            'customer_id'        => $this->user->id,
            'full_name'          => 'Test Investor',
            'name_with_initials' => 'T. Investor',
            'customer_code'      => 'CUST-001',
            'id_type'            => 'nic',
            'id_number'          => '199512345678',
            'email'              => 'investor@test.com',
            'phone_primary'      => '0771234567',
            'date_of_birth'      => '1995-01-01',
            'is_active'          => true,
        ]);

        // 3. Create geolocation models & branch
        $country = Country::create([
            'name'      => 'Sri Lanka',
            'code'      => 'LK',
            'is_active' => true,
        ]);

        $province = Province::create([
            'name'       => 'Western Province',
            'code'       => 'WP',
            'country_id' => $country->id,
            'is_active'  => true,
        ]);

        $zone = Zone::create([
            'name'        => 'Colombo Zone',
            'code'        => 'ZONE-COL',
            'province_id' => $province->id,
            'is_active'   => true,
        ]);

        $region = Region::create([
            'name'      => 'Colombo Region',
            'code'      => 'REG-COL',
            'zone_id'   => $zone->id,
            'is_active' => true,
        ]);

        $this->branch = Branch::create([
            'name'          => 'Colombo Branch',
            'code'          => 'COL',
            'address_line1' => 'No. 123, Galle Road',
            'city'          => 'Colombo',
            'zone_id'       => $zone->id,
            'region_id'     => $region->id,
            'province_id'   => $province->id,
            'phone_primary' => '0112345678',
            'opening_date'  => '2026-01-01',
            'is_active'     => true,
        ]);

        // 4. Create investment product
        $this->product = InvestmentProduct::create([
            'name'            => '12-Month Gold Return',
            'code'            => 'GLD-12',
            'duration_months' => 12,
            'roi_percentage'  => 12.00,
            'is_active'       => true,
            'plan_type'       => 'normal',
        ]);

        // 5. Create investment for this customer
        $this->investment = Investment::create([
            'policy_number'         => 'POL-2026-0001',
            'application_number'    => 'APP-2026-0001',
            'sales_code'            => 'SALES-001',
            'reservation_date'      => '2026-01-01',
            'target_period_key'     => '2026-01',
            'customer_id'           => $this->customer->id,
            'branch_id'             => $this->branch->id,
            'created_by'            => $this->agent->id,
            'unit_head_id'          => $this->agent->id,
            'investment_product_id' => $this->product->id,
            'investment_amount'     => 100000.00,
            'payment_proof'         => 'proof.jpg',
            'status'                => 'approved',
        ]);
    }

    public function test_unauthenticated_request_is_rejected()
    {
        $response = $this->getJson('/api/v1/investor/investments');
        $response->assertStatus(401);
    }

    public function test_customer_can_list_investments_with_required_fields()
    {
        $token = auth('api')->login($this->user);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/investor/investments');

        $response->assertStatus(200)
            ->assertJson([
                'status'  => 'success',
                'message' => 'Investments fetched successfully',
            ])
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'policy_number',
                            'application_number',
                            'status',
                            'plan_name',
                            'reservation_date',
                            'amount',
                            'expired_date',
                            'total_maturity_amount',
                        ]
                    ]
                ]
            ]);

        $item = $response->json('data.data.0');
        $this->assertEquals('POL-2026-0001', $item['policy_number']);
        $this->assertEquals('12-Month Gold Return', $item['plan_name']);
        $this->assertEquals('2026-01-01', $item['reservation_date']);
        $this->assertEquals(100000.00, $item['amount']);
        $this->assertEquals('2027-01-01', $item['expired_date']);
        $this->assertEquals(112000.00, $item['total_maturity_amount']);
    }

    public function test_customer_can_list_investments_with_branch_and_agent_flags()
    {
        $token = auth('api')->login($this->user);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/investor/investments?branch=true&agent=true');

        $response->assertStatus(200);

        $item = $response->json('data.data.0');
        $this->assertArrayHasKey('branch', $item);
        $this->assertEquals('Colombo Branch', $item['branch']['name']);
        $this->assertEquals('COL', $item['branch']['code']);

        $this->assertArrayHasKey('agent', $item);
        $this->assertEquals('Agent Kamal', $item['agent']['name']);
        $this->assertEquals('EMP-007', $item['agent']['employee_code']);
    }

    public function test_customer_can_view_single_investment_by_id()
    {
        $token = auth('api')->login($this->user);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/v1/investor/investments/{$this->investment->id}?branch=true&agent=true");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'id'                    => $this->investment->id,
                    'policy_number'         => 'POL-2026-0001',
                    'plan_name'             => '12-Month Gold Return',
                    'reservation_date'      => '2026-01-01',
                    'amount'                => 100000.00,
                    'expired_date'          => '2027-01-01',
                    'total_maturity_amount' => 112000.00,
                    'branch'                => [
                        'name' => 'Colombo Branch',
                        'code' => 'COL',
                    ],
                    'agent'                 => [
                        'name'          => 'Agent Kamal',
                        'employee_code' => 'EMP-007',
                    ],
                ]
            ]);
    }

    public function test_customer_cannot_view_another_customers_investment()
    {
        // Another customer
        $otherCustomer = Customer::create([
            'full_name'          => 'Other Customer',
            'name_with_initials' => 'O. Customer',
            'customer_code'      => 'CUST-002',
            'id_type'            => 'nic',
            'id_number'          => '200098765432',
            'phone_primary'      => '0779998888',
            'date_of_birth'      => '2000-01-01',
            'is_active'          => true,
        ]);

        $otherInvestment = Investment::create([
            'policy_number'         => 'POL-2026-9999',
            'application_number'    => 'APP-2026-9999',
            'sales_code'            => 'SALES-999',
            'reservation_date'      => '2026-02-01',
            'target_period_key'     => '2026-02',
            'customer_id'           => $otherCustomer->id,
            'branch_id'             => $this->branch->id,
            'created_by'            => $this->agent->id,
            'unit_head_id'          => $this->agent->id,
            'investment_product_id' => $this->product->id,
            'investment_amount'     => 200000.00,
            'payment_proof'         => 'proof2.jpg',
            'status'                => 'approved',
        ]);

        $token = auth('api')->login($this->user);

        // Attempt to access other customer's investment
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/v1/investor/investments/{$otherInvestment->id}");

        $response->assertStatus(404)
            ->assertJson([
                'status'  => 'error',
                'message' => 'Investment not found.',
            ]);
    }

    public function test_customer_can_search_and_filter_investments()
    {
        $token = auth('api')->login($this->user);

        // Search by policy number
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/investor/investments?search=POL-2026-0001');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));

        // Search for non-existent policy
        $responseNone = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/investor/investments?search=NONEXISTENT');

        $responseNone->assertStatus(200);
        $this->assertCount(0, $responseNone->json('data.data'));
    }

    public function test_customer_can_view_investment_summary()
    {
        $token = auth('api')->login($this->user);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/investor/investments/summary');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'total_invested_amount'    => 100000.00,
                    'active_invested_amount'   => 100000.00,
                    'total_projected_maturity' => 112000.00,
                    'counts' => [
                        'total'  => 1,
                        'active' => 1,
                    ],
                ],
            ]);
    }

    public function test_customer_can_view_single_investment_payouts()
    {
        // Create a sample payout
        InvestmentPayout::create([
            'investment_id'  => $this->investment->id,
            'scheduled_date' => '2026-02-01',
            'amount'         => 1000.00,
            'status'         => 'unpaid',
        ]);

        $token = auth('api')->login($this->user);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/v1/investor/investments/{$this->investment->id}/payouts");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data'   => [
                    'investment_id' => $this->investment->id,
                    'policy_number' => 'POL-2026-0001',
                    'summary'       => [
                        'total_count'   => 1,
                        'total_amount'  => 1000.00,
                        'unpaid_count'  => 1,
                        'unpaid_amount' => 1000.00,
                    ],
                ],
            ]);
    }

    public function test_anyone_can_list_investment_products()
    {
        $response = $this->getJson('/api/v1/investor/investment-products');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertNotEmpty($response->json('data'));
    }
}
