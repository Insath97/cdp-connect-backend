<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBankDetail;
use App\Models\ExpiredInvestment;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExpiredBusinessModuleTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $adminToken;
    protected $normalUser;
    protected $customer;
    protected $product;
    protected $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        $permissions = [
            'Expired Business Index',
            'Expired Business Update',
            'Expired Investment Index',
            'Expired Investment Update',
        ];
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api'], ['group_name' => 'Expired Business Management Permissions']);
        }

        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);
        $superAdminRole->syncPermissions($permissions);

        // Create admin user
        $this->adminUser = User::create([
            'name' => 'Admin User',
            'username' => 'admin_user',
            'email' => 'admin@cdp.lk',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->adminUser->assignRole($superAdminRole);
        $this->adminToken = auth('api')->login($this->adminUser);

        // Create normal user without permissions
        $this->normalUser = User::create([
            'name' => 'Hierarchy User',
            'username' => 'hierarchy_user',
            'email' => 'hierarchy@cdp.lk',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'is_active' => true,
            'can_login' => true,
        ]);

        // Create geolocation models
        $country = \App\Models\Country::create([
            'name' => 'Sri Lanka',
            'code' => 'LK',
            'is_active' => true,
        ]);

        $province = \App\Models\Province::create([
            'name' => 'Western Province',
            'code' => 'WP',
            'country_id' => $country->id,
            'is_active' => true,
        ]);

        $zone = \App\Models\Zone::create([
            'name' => 'Colombo Zone',
            'code' => 'ZONE-COL',
            'province_id' => $province->id,
            'is_active' => true,
        ]);

        $region = \App\Models\Region::create([
            'name' => 'Colombo Region',
            'code' => 'REG-COL',
            'zone_id' => $zone->id,
            'is_active' => true,
        ]);

        // Create branch
        $this->branch = Branch::create([
            'name' => 'Colombo Central',
            'code' => 'COL',
            'address_line1' => 'No. 123, Galle Road',
            'city' => 'Colombo',
            'zone_id' => $zone->id,
            'region_id' => $region->id,
            'province_id' => $province->id,
            'phone_primary' => '0112345678',
            'opening_date' => '2026-01-01',
            'is_active' => true,
        ]);

        // Create customer
        $this->customer = Customer::create([
            'full_name' => 'Kamal Perera',
            'name_with_initials' => 'K. Perera',
            'customer_code' => 'CUST0099',
            'id_type' => 'nic',
            'id_number' => '199012345678',
            'date_of_birth' => '1990-01-01',
            'phone_primary' => '0771234567',
        ]);

        // Create customer bank detail
        CustomerBankDetail::create([
            'customer_id' => $this->customer->id,
            'bank_name' => 'Commercial Bank',
            'branch_name' => 'Kollupitiya',
            'account_number' => '1234567890',
        ]);

        // Create investment product
        $this->product = InvestmentProduct::create([
            'name' => 'Gold Maturity Plan',
            'code' => 'GMP-12',
            'roi_percentage' => 15.00,
            'duration_months' => 12,
            'is_active' => true,
        ]);
    }

    protected function adminHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->adminToken}"];
    }

    protected function createInvestment(string $status = 'expired', float $amount = 500000.00): Investment
    {
        return Investment::create([
            'policy_number' => 'POL-' . uniqid(),
            'application_number' => 'APP-' . uniqid(),
            'sales_code' => 'SC-' . uniqid(),
            'reservation_date' => now()->subMonths(13)->format('Y-m-d'),
            'target_period_key' => now()->subMonths(13)->format('Y-m'),
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => $amount,
            'initial_payment' => $amount,
            'unit_head_id' => $this->adminUser->id,
            'status' => $status,
            'created_by' => $this->adminUser->id,
        ]);
    }

    /** @test */
    public function test_it_lists_only_expired_business_and_auto_syncs_settlement_records()
    {
        $expired1 = $this->createInvestment('expired', 300000.00);
        $expired2 = $this->createInvestment('expired', 700000.00);
        $approved = $this->createInvestment('approved', 500000.00);
        $pending = $this->createInvestment('pending', 200000.00);

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/expired-business');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'stats' => [
                    'total_expired_count',
                    'total_unpaid_count',
                    'total_paid_count',
                    'total_expired_amount',
                    'total_unpaid_amount',
                    'total_paid_amount',
                ],
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'investment_id',
                            'investment_amount',
                            'status',
                            'investment' => [
                                'policy_number',
                                'customer' => [
                                    'full_name',
                                    'phone_primary',
                                ],
                                'branch',
                                'investment_product',
                            ],
                        ],
                    ],
                ],
            ]);

        $responseData = $response->json();

        // Exactly 2 expired investments should be listed
        $this->assertEquals(2, $responseData['stats']['total_expired_count']);
        $this->assertEquals(2, $responseData['stats']['total_unpaid_count']);
        $this->assertEquals(0, $responseData['stats']['total_paid_count']);
        $this->assertEquals(1000000.00, $responseData['stats']['total_expired_amount']);
        $this->assertEquals(1000000.00, $responseData['stats']['total_unpaid_amount']);

        // Check that approved and pending investments are not in the response
        $returnedPolicyNumbers = collect($responseData['data']['data'])->pluck('investment.policy_number')->toArray();
        $this->assertContains($expired1->policy_number, $returnedPolicyNumbers);
        $this->assertContains($expired2->policy_number, $returnedPolicyNumbers);
        $this->assertNotContains($approved->policy_number, $returnedPolicyNumbers);
        $this->assertNotContains($pending->policy_number, $returnedPolicyNumbers);
    }

    /** @test */
    public function test_it_shows_single_expired_business_details()
    {
        $investment = $this->createInvestment('expired', 450000.00);
        $settlement = ExpiredInvestment::create([
            'investment_id' => $investment->id,
            'customer_id' => $investment->customer_id,
            'branch_id' => $investment->branch_id,
            'investment_amount' => $investment->investment_amount,
            'status' => 'unpaid',
        ]);

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson("/api/v1/expired-business/{$settlement->id}");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.investment_amount', '450000.00')
            ->assertJsonPath('data.investment.policy_number', $investment->policy_number);
    }

    /** @test */
    public function test_it_updates_settlement_payment_to_paid_with_receipt_image_and_transaction_number()
    {
        $investment = $this->createInvestment('expired', 500000.00);
        $settlement = ExpiredInvestment::create([
            'investment_id' => $investment->id,
            'customer_id' => $investment->customer_id,
            'branch_id' => $investment->branch_id,
            'investment_amount' => $investment->investment_amount,
            'status' => 'unpaid',
        ]);

        $fakeFile = UploadedFile::fake()->image('payment_receipt.jpg', 600, 400);

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/v1/expired-business/{$settlement->id}/payment", [
                'status' => 'paid',
                'investment_amount' => 500000.00,
                'payment_method' => 'bank_transfer',
                'transaction_number' => 'TXN-987654321',
                'remarks' => 'Settlement paid via commercial bank transfer',
                'image' => $fakeFile,
                'paid_at' => now()->format('Y-m-d H:i:s'),
                'send_sms' => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_method', 'bank_transfer')
            ->assertJsonPath('data.transaction_number', 'TXN-987654321')
            ->assertJsonPath('data.remarks', 'Settlement paid via commercial bank transfer');

        $settlement->refresh();
        $this->assertEquals('paid', $settlement->status);
        $this->assertEquals('TXN-987654321', $settlement->transaction_number);
        $this->assertEquals('bank_transfer', $settlement->payment_method);
        $this->assertNotNull($settlement->paid_at);
        $this->assertEquals($this->adminUser->id, $settlement->paid_by);
        $this->assertNotNull($settlement->image);
        $this->assertTrue(File::exists(public_path($settlement->image)));

        // Clean up test file
        if (File::exists(public_path($settlement->image))) {
            File::delete(public_path($settlement->image));
        }
    }

    /** @test */
    public function test_it_validates_required_fields_when_marking_paid()
    {
        $investment = $this->createInvestment('expired', 250000.00);
        $settlement = ExpiredInvestment::create([
            'investment_id' => $investment->id,
            'customer_id' => $investment->customer_id,
            'branch_id' => $investment->branch_id,
            'investment_amount' => $investment->investment_amount,
            'status' => 'unpaid',
        ]);

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/v1/expired-business/{$settlement->id}/payment", [
                'status' => 'paid',
                // payment_method and transaction_number are missing
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method', 'transaction_number']);
    }

    /** @test */
    public function test_it_can_switch_settlement_back_to_unpaid()
    {
        $investment = $this->createInvestment('expired', 250000.00);
        $settlement = ExpiredInvestment::create([
            'investment_id' => $investment->id,
            'customer_id' => $investment->customer_id,
            'branch_id' => $investment->branch_id,
            'investment_amount' => $investment->investment_amount,
            'status' => 'paid',
            'payment_method' => 'cash',
            'transaction_number' => 'CASH-001',
            'paid_at' => now(),
            'paid_by' => $this->adminUser->id,
        ]);

        $response = $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/v1/expired-business/{$settlement->id}/status", [
                'status' => 'unpaid',
                'remarks' => 'Payment cancelled and set back to unpaid',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'unpaid');

        $settlement->refresh();
        $this->assertEquals('unpaid', $settlement->status);
        $this->assertNull($settlement->paid_at);
        $this->assertNull($settlement->paid_by);
    }

    /** @test */
    public function test_it_filters_by_paid_and_unpaid_settlement_status()
    {
        $inv1 = $this->createInvestment('expired', 100000.00);
        $inv2 = $this->createInvestment('expired', 200000.00);

        ExpiredInvestment::create([
            'investment_id' => $inv1->id,
            'customer_id' => $inv1->customer_id,
            'branch_id' => $inv1->branch_id,
            'investment_amount' => $inv1->investment_amount,
            'status' => 'unpaid',
        ]);

        ExpiredInvestment::create([
            'investment_id' => $inv2->id,
            'customer_id' => $inv2->customer_id,
            'branch_id' => $inv2->branch_id,
            'investment_amount' => $inv2->investment_amount,
            'status' => 'paid',
            'payment_method' => 'bank_transfer',
            'transaction_number' => 'TXN-PAID-001',
            'paid_at' => now(),
        ]);

        // Filter by unpaid
        $unpaidRes = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/expired-business?settlement_status=unpaid');

        $unpaidRes->assertStatus(200);
        $unpaidItems = $unpaidRes->json('data.data');
        $this->assertCount(1, $unpaidItems);
        $this->assertEquals('unpaid', $unpaidItems[0]['status']);

        // Filter by paid
        $paidRes = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/expired-business?settlement_status=paid');

        $paidRes->assertStatus(200);
        $paidItems = $paidRes->json('data.data');
        $this->assertCount(1, $paidItems);
        $this->assertEquals('paid', $paidItems[0]['status']);
    }

    /** @test */
    public function test_it_denies_access_without_proper_permissions()
    {
        $token = auth('api')->login($this->normalUser);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/expired-business');

        $response->assertStatus(403);
    }

    /** @test */
    public function test_alias_route_expired_investments_works_identically()
    {
        $investment = $this->createInvestment('expired', 800000.00);

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/expired-investments');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('stats.total_expired_count', 1);
    }
}
