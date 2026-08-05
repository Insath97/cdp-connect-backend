<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InvestmentProduct;
use App\Models\Investment;
use App\Models\InvestmentPayout;
use App\Models\Target;
use App\Models\Country;
use App\Models\Province;
use App\Models\Zone;
use App\Models\Region;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PayoutRecalculationTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $branch;
    protected $customer;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PermissionsSeeder::class);
        $this->seed(\Database\Seeders\LevelSeeder::class);

        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);
        $superAdminRole->syncPermissions(Permission::all());

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'username' => 'admin_user',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);
        $this->adminUser->assignRole($superAdminRole);

        $country = Country::create(['name' => 'Sri Lanka', 'code' => 'SL', 'is_active' => true]);
        $province = Province::create(['name' => 'Western', 'code' => 'WP', 'country_id' => $country->id, 'is_active' => true]);
        $zone = Zone::create(['name' => 'Colombo', 'code' => 'ZONE-COL', 'province_id' => $province->id, 'is_active' => true]);
        $region = Region::create(['name' => 'Colombo', 'code' => 'REG-COL', 'zone_id' => $zone->id, 'is_active' => true]);

        $this->branch = Branch::create([
            'name' => 'Colombo Branch',
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

        $this->customer = Customer::create([
            'full_name' => 'Jane Doe',
            'name_with_initials' => 'J. Doe',
            'customer_code' => 'CUST001',
            'id_type' => 'nic',
            'id_number' => '123456789V',
            'email' => 'jane@example.com',
            'date_of_birth' => '1995-01-01',
            'phone_primary' => '0771234567',
        ]);

        $this->product = InvestmentProduct::create([
            'name' => 'Savings Plan',
            'code' => 'SP001',
            'roi_percentage' => 12.00,
            'duration_months' => 12,
            'is_active' => true,
        ]);

        Target::create([
            'user_id' => $this->adminUser->id,
            'assigned_by' => $this->adminUser->id,
            'period_type' => 'month',
            'period_key' => now()->format('Y-m'),
            'target_amount' => 1000000.00,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function test_updating_investment_amount_recalculates_payouts()
    {
        $token = auth('api')->login($this->adminUser);

        // 1. Create an investment of 100,000 at 12% ROI. Monthly return should be 1000.00.
        $createResponse = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/investments', [
                'reservation_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'investment_product_id' => $this->product->id,
                'investment_amount' => 100000.00,
                'business_type' => 'counter_business',
                'bank' => 'HNB',
                'payment_type' => 'full_payment',
                'initial_payment' => 100000.00,
                'unit_head_id' => $this->adminUser->id,
            ]);

        $createResponse->assertStatus(201);
        $investmentId = $createResponse->json('data.id');

        // Set billing status to received
        $billing = \App\Models\Billing::where('investment_id', $investmentId)->first();
        if ($billing) {
            $billing->update(['status' => 'received']);
        }

        // Approve via endpoint so payouts are generated
        $approveResponse = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->patchJson("/api/v1/investments/{$investmentId}/approve");
        $approveResponse->assertStatus(200);

        $this->assertDatabaseHas('investment_payouts', [
            'investment_id' => $investmentId,
            'amount' => 1000.00
        ]);

        // 2. Update the investment amount to 200,000 (monthly return should become 2000.00)
        $updateResponse = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/v1/investments/{$investmentId}", [
                'investment_amount' => 200000.00,
            ]);

        $updateResponse->assertStatus(200);

        // Payouts should automatically update
        $this->assertDatabaseHas('investment_payouts', [
            'investment_id' => $investmentId,
            'amount' => 2000.00
        ]);
        $this->assertDatabaseMissing('investment_payouts', [
            'investment_id' => $investmentId,
            'amount' => 1000.00
        ]);
    }

    /** @test */
    public function test_updating_product_roi_recalculates_payouts()
    {
        $token = auth('api')->login($this->adminUser);

        // 1. Create an investment of 100,000 at 12% ROI. Monthly return should be 1000.00.
        $investment = Investment::create([
            'reservation_date' => now()->format('Y-m-d'),
            'target_period_key' => now()->format('Y-m'),
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 100000.00,
            'business_type' => 'counter_business',
            'bank' => 'HNB',
            'payment_type' => 'full_payment',
            'initial_payment' => 100000.00,
            'unit_head_id' => $this->adminUser->id,
            'status' => 'approved',
            'application_number' => 'APP-COL-26080001',
            'sales_code' => 'COL-0001',
            'created_by' => $this->adminUser->id,
        ]);

        // Seed initial payouts
        for ($i = 1; $i <= 12; $i++) {
            InvestmentPayout::create([
                'investment_id' => $investment->id,
                'scheduled_date' => now()->addMonths($i)->format('Y-m-d'),
                'amount' => 1000.00,
                'status' => 'unpaid'
            ]);
        }

        // 2. Update the product ROI to 18% (monthly return should become 1500.00)
        $updateResponse = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/v1/investment-products/{$this->product->id}", [
                'name' => 'Savings Plan Updated',
                'code' => 'SP001',
                'roi_percentage' => 18.00,
                'duration_months' => 12,
            ]);

        $updateResponse->assertStatus(200);

        // Payouts should automatically update
        $this->assertDatabaseHas('investment_payouts', [
            'investment_id' => $investment->id,
            'amount' => 1500.00
        ]);
        $this->assertDatabaseMissing('investment_payouts', [
            'investment_id' => $investment->id,
            'amount' => 1000.00
        ]);
    }

    /** @test */
    public function test_endpoint_recalculates_payouts_by_investment_id()
    {
        $token = auth('api')->login($this->adminUser);

        $investment = Investment::create([
            'reservation_date' => now()->format('Y-m-d'),
            'target_period_key' => now()->format('Y-m'),
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 100000.00,
            'business_type' => 'counter_business',
            'bank' => 'HNB',
            'payment_type' => 'full_payment',
            'initial_payment' => 100000.00,
            'unit_head_id' => $this->adminUser->id,
            'status' => 'approved',
            'application_number' => 'APP-COL-26080002',
            'sales_code' => 'COL-0002',
            'created_by' => $this->adminUser->id,
        ]);

        InvestmentPayout::create([
            'investment_id' => $investment->id,
            'scheduled_date' => now()->addMonth()->format('Y-m-d'),
            'amount' => 500.00, // outdated payout amount
            'status' => 'unpaid'
        ]);

        // Trigger endpoint for specific investment ID
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/investments/recalculate-payouts', [
                'investment_id' => $investment->id
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Successfully recalculated unpaid payouts for 1 investment(s).'
        ]);

        // Verify payout amount is updated to 1000.00
        $this->assertDatabaseHas('investment_payouts', [
            'investment_id' => $investment->id,
            'amount' => 1000.00
        ]);
    }

    /** @test */
    public function test_endpoint_recalculates_payouts_by_period_key()
    {
        $token = auth('api')->login($this->adminUser);

        $periodKey = '2026-09';

        $investment = Investment::create([
            'reservation_date' => '2026-09-01',
            'target_period_key' => $periodKey,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 100000.00,
            'business_type' => 'counter_business',
            'bank' => 'HNB',
            'payment_type' => 'full_payment',
            'initial_payment' => 100000.00,
            'unit_head_id' => $this->adminUser->id,
            'status' => 'approved',
            'application_number' => 'APP-COL-26080003',
            'sales_code' => 'COL-0003',
            'created_by' => $this->adminUser->id,
        ]);

        InvestmentPayout::create([
            'investment_id' => $investment->id,
            'scheduled_date' => '2026-10-01',
            'amount' => 500.00, // outdated payout amount
            'status' => 'unpaid'
        ]);

        // Trigger endpoint for specific target period key
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/investments/recalculate-payouts', [
                'period_key' => $periodKey
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Successfully recalculated unpaid payouts for 1 investment(s).'
        ]);

        // Verify payout amount is updated to 1000.00
        $this->assertDatabaseHas('investment_payouts', [
            'investment_id' => $investment->id,
            'amount' => 1000.00
        ]);
    }

    /** @test */
    public function test_endpoint_validation_rules()
    {
        $token = auth('api')->login($this->adminUser);

        // Neither investment_id nor period_key provided
        $response1 = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/investments/recalculate-payouts', []);

        $response1->assertStatus(422);

        // Invalid period key format
        $response2 = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/investments/recalculate-payouts', [
                'period_key' => '2026/09'
            ]);

        $response2->assertStatus(422);
    }
}
