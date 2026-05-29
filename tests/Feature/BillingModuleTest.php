<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\Billing;
use App\Models\User;
use App\Models\Target;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Carbon\Carbon;

class BillingModuleTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $customer;
    protected $product;
    protected $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // Bypass permission middleware checks during test Setup
        Gate::before(fn () => true);

        // Create a user
        $this->user = User::create([
            'name' => 'Admin User',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
            'is_active' => true,
            'can_login' => true,
        ]);

        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'api']);
        $this->user->assignRole($role);

        // Create a branch
        $this->branch = Branch::create([
            'name' => 'Colombo Branch',
            'code' => 'COL',
            'is_active' => true,
        ]);

        // Create a customer
        $this->customer = Customer::create([
            'full_name' => 'Jane Doe',
            'name_with_initials' => 'J. Doe',
            'customer_code' => 'CUST001',
            'id_type' => 'nic',
            'id_number' => '123456789V',
            'email' => 'jane@example.com',
        ]);

        // Create investment product
        $this->product = InvestmentProduct::create([
            'name' => 'Savings Plan',
            'code' => 'SP001',
            'roi_percentage' => 10.00,
            'duration_months' => 12,
            'is_active' => true,
        ]);
        
        // Assign a target to the user for the current month so target verification doesn't fail
        Target::create([
            'user_id' => $this->user->id,
            'period_key' => now()->format('Y-m'),
            'target_amount' => 1000000.00,
            'achieved_amount' => 0.00,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function test_it_creates_bank_deposit_investment_with_payment_proof()
    {
        // 1. If bank deposit is selected, payment_proof is required
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/investments', [
                'reservation_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'investment_product_id' => $this->product->id,
                'investment_amount' => 500000.00,
                'business_type' => 'bank_deposit',
                'bank' => 'HNB',
                'payment_type' => 'full_payment',
                'initial_payment' => 500000.00,
                'unit_head_id' => $this->user->id,
            ]);

        $response->assertStatus(422); // Validation fails because payment_proof is required
    }

    /** @test */
    public function test_it_creates_counter_business_investment_and_generates_billing_record()
    {
        // 2. If counter business is selected, payment_proof is optional, and a Billing record is created
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/investments', [
                'reservation_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'investment_product_id' => $this->product->id,
                'investment_amount' => 500000.00,
                'business_type' => 'counter_business',
                'bank' => 'HNB',
                'payment_type' => 'full_payment',
                'initial_payment' => 500000.00,
                'unit_head_id' => $this->user->id,
            ]);

        $response->assertStatus(201);

        $investmentId = $response->json('data.id');
        $this->assertDatabaseHas('investments', [
            'id' => $investmentId,
            'business_type' => 'counter_business',
        ]);

        $this->assertDatabaseHas('billings', [
            'investment_id' => $investmentId,
            'customer_id' => $this->customer->id,
            'investment_amount' => 500000.00,
            'status' => 'pending',
        ]);
        
        $billing = Billing::where('investment_id', $investmentId)->first();
        $this->assertNotNull($billing);
        $this->assertStringStartsWith('BIL-COL-' . now()->format('ym'), $billing->billing_number);
    }

    /** @test */
    public function test_it_blocks_investment_approval_if_billing_is_pending()
    {
        // Create the investment
        $investment = Investment::create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 500000.00,
            'payment_type' => 'full_payment',
            'status' => 'pending',
            'business_type' => 'counter_business',
            'created_by' => $this->user->id,
            'unit_head_id' => $this->user->id,
            'target_period_key' => now()->format('Y-m'),
            'reservation_date' => now(),
            'application_number' => 'APP-COL-1',
            'sales_code' => 'COL-1',
            'payment_proof' => '',
        ]);

        // Create the billing as pending
        Billing::create([
            'billing_number' => 'BIL-COL-26050001',
            'customer_id' => $this->customer->id,
            'investment_id' => $investment->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 500000.00,
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);

        // Approve must fail
        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/v1/investments/{$investment->id}/approve");

        $response->assertStatus(422);
        $this->assertEquals('This investment cannot be approved before the billing status is received.', $response->json('message'));
    }

    /** @test */
    public function test_it_allows_approval_after_billing_status_is_received()
    {
        // Create the investment
        $investment = Investment::create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 500000.00,
            'payment_type' => 'full_payment',
            'status' => 'pending',
            'business_type' => 'counter_business',
            'created_by' => $this->user->id,
            'unit_head_id' => $this->user->id,
            'target_period_key' => now()->format('Y-m'),
            'reservation_date' => now(),
            'application_number' => 'APP-COL-2',
            'sales_code' => 'COL-2',
            'payment_proof' => '',
        ]);

        // Create the billing
        $billing = Billing::create([
            'billing_number' => 'BIL-COL-26050002',
            'customer_id' => $this->customer->id,
            'investment_id' => $investment->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 500000.00,
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);

        // Update billing to received
        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/v1/billings/{$billing->id}/status");
        $response->assertStatus(200);
        
        $this->assertNotNull($billing->fresh()->status_updated_at);

        // Approve should now succeed
        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/v1/investments/{$investment->id}/approve");

        $response->assertStatus(200);
        $this->assertEquals('approved', $investment->fresh()->status);
    }

    /** @test */
    public function test_billing_index_returns_filterable_statistics()
    {
        // Create two billings with different amounts and statuses
        Billing::create([
            'billing_number' => 'BIL-COL-26050003',
            'customer_id' => $this->customer->id,
            'investment_id' => 1,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 100000.00,
            'branch_id' => $this->branch->id,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        Billing::create([
            'billing_number' => 'BIL-COL-26050004',
            'customer_id' => $this->customer->id,
            'investment_id' => 2,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 200000.00,
            'branch_id' => $this->branch->id,
            'status' => 'received',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/billings');

        $response->assertStatus(200);
        $response->assertJsonPath('stats.total_amount_pending', 100000.00);
        $response->assertJsonPath('stats.total_amount_received', 200000.00);
    }

    /** @test */
    public function test_it_does_not_require_payment_proof_when_updating_if_already_exists()
    {
        // Create a bank deposit investment
        $investment = Investment::create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 500000.00,
            'payment_type' => 'full_payment',
            'status' => 'pending',
            'business_type' => 'bank_deposit',
            'created_by' => $this->user->id,
            'unit_head_id' => $this->user->id,
            'target_period_key' => now()->format('Y-m'),
            'reservation_date' => now(),
            'application_number' => 'APP-COL-10',
            'sales_code' => 'COL-10',
            'payment_proof' => 'existing_proof.jpg',
        ]);

        // Attempting to update notes (without re-uploading file) should succeed
        $response = $this->actingAs($this->user, 'api')
            ->putJson("/api/v1/investments/{$investment->id}", [
                'notes' => 'Updated notes',
                'business_type' => 'bank_deposit',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('Updated notes', $investment->fresh()->notes);
    }
}
