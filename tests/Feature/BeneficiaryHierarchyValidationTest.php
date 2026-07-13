<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\User;
use App\Models\Target;
use App\Models\Beneficiary;
use App\Models\Legal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class BeneficiaryHierarchyValidationTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $hierarchyUser;
    protected $branch;
    protected $customer;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);

        // Create an admin user
        $this->adminUser = User::create([
            'name' => 'Admin User',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
            'is_active' => true,
            'can_login' => true,
            'id_type' => 'nic',
            'id_number' => '111111111V',
        ]);

        // Create a hierarchy staff user
        $this->hierarchyUser = User::create([
            'name' => 'Hierarchy User',
            'username' => 'hierarchy_staff',
            'email' => 'staff@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'hierarchy',
            'is_active' => true,
            'can_login' => true,
            'id_type' => 'passport',
            'id_number' => 'N9999999',
        ]);

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

        // Create Target for period Y-m to satisfy validation
        $targetPeriodKey = now()->format('Y-m');
        Target::create([
            'user_id' => $this->adminUser->id,
            'period_key' => $targetPeriodKey,
            'target_amount' => 1000000.00,
            'achieved_amount' => 0,
        ]);
    }

    /** @test */
    public function test_investment_creation_fails_when_nested_beneficiary_is_admin_user()
    {
        $response = $this->actingAs($this->adminUser, 'api')
            ->postJson('/api/v1/investments', [
                'reservation_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'investment_product_id' => $this->product->id,
                'investment_amount' => 50000.00,
                'bank' => 'HNB',
                'payment_type' => 'full_payment',
                'initial_payment' => 50000.00,
                'unit_head_id' => $this->adminUser->id,
                'beneficiary' => [
                    'full_name' => 'Admin User Beneficiary',
                    'type' => 'adult',
                    'id_type' => 'nic',
                    'id_number' => '111111111V', // matches admin user
                    'phone_primary' => '0712345678',
                    'relationship' => 'Self',
                    'share_percentage' => 100.00,
                ]
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['field' => 'beneficiary.id_number']);
    }

    /** @test */
    public function test_investment_creation_fails_when_nested_beneficiary_is_hierarchy_user()
    {
        $response = $this->actingAs($this->adminUser, 'api')
            ->postJson('/api/v1/investments', [
                'reservation_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'investment_product_id' => $this->product->id,
                'investment_amount' => 50000.00,
                'bank' => 'HNB',
                'payment_type' => 'full_payment',
                'initial_payment' => 50000.00,
                'unit_head_id' => $this->adminUser->id,
                'beneficiary' => [
                    'full_name' => 'Staff Beneficiary',
                    'type' => 'adult',
                    'id_type' => 'passport',
                    'id_number' => 'N9999999', // matches hierarchy user
                    'phone_primary' => '0712345678',
                    'relationship' => 'Manager',
                    'share_percentage' => 100.00,
                ]
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['field' => 'beneficiary.id_number']);
    }

    /** @test */
    public function test_investment_creation_passes_when_nested_beneficiary_is_not_staff()
    {
        $response = $this->actingAs($this->adminUser, 'api')
            ->postJson('/api/v1/investments', [
                'reservation_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'investment_product_id' => $this->product->id,
                'investment_amount' => 50000.00,
                'bank' => 'HNB',
                'payment_type' => 'full_payment',
                'initial_payment' => 50000.00,
                'unit_head_id' => $this->adminUser->id,
                'beneficiary' => [
                    'full_name' => 'Normal Beneficiary',
                    'type' => 'adult',
                    'id_type' => 'nic',
                    'id_number' => '991234567V', // distinct
                    'phone_primary' => '0712345678',
                    'relationship' => 'Brother',
                    'share_percentage' => 100.00,
                ]
            ]);

        $response->assertStatus(201);
    }

    /** @test */
    public function test_investment_creation_fails_when_selected_beneficiary_id_belongs_to_staff()
    {
        // Pre-create beneficiary that has staff details
        $beneficiary = Beneficiary::create([
            'customer_id' => $this->customer->id,
            'full_name' => 'Staff Beneficiary',
            'type' => 'adult',
            'id_type' => 'passport',
            'id_number' => 'N9999999', // matches hierarchy user
            'phone_primary' => '0712345678',
            'relationship' => 'Manager',
            'share_percentage' => 100.00,
        ]);

        $response = $this->actingAs($this->adminUser, 'api')
            ->postJson('/api/v1/investments', [
                'reservation_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'investment_product_id' => $this->product->id,
                'investment_amount' => 50000.00,
                'bank' => 'HNB',
                'payment_type' => 'full_payment',
                'initial_payment' => 50000.00,
                'unit_head_id' => $this->adminUser->id,
                'beneficiary_id' => $beneficiary->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['field' => 'beneficiary_id']);
    }

    /** @test */
    public function test_investment_update_fails_when_nested_beneficiary_is_staff()
    {
        // Pre-create investment
        $investment = Investment::create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 50000.00,
            'payment_type' => 'bank',
            'status' => 'pending',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser, 'api')
            ->putJson('/api/v1/investments/' . $investment->id, [
                'beneficiary' => [
                    'full_name' => 'Staff Beneficiary',
                    'type' => 'adult',
                    'id_type' => 'passport',
                    'id_number' => 'N9999999', // matches hierarchy user
                    'phone_primary' => '0712345678',
                    'relationship' => 'Manager',
                    'share_percentage' => 100.00,
                ]
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['field' => 'beneficiary.id_number']);
    }

    /** @test */
    public function test_legal_creation_fails_when_beneficiary_is_staff()
    {
        $investment = Investment::create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 50000.00,
            'payment_type' => 'bank',
            'status' => 'approved',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser, 'api')
            ->postJson('/api/v1/legals', [
                'investment_id' => $investment->id,
                'language' => 'english',
                'beneficiary_full_name' => 'Staff Beneficiary',
                'beneficiary_id_type' => 'passport',
                'beneficiary_id_number' => 'N9999999', // matches hierarchy user
                'beneficiary_relationship' => 'Manager',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['field' => 'beneficiary_id_number']);
    }

    /** @test */
    public function test_legal_update_fails_when_beneficiary_is_staff()
    {
        $investment = Investment::create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 50000.00,
            'payment_type' => 'bank',
            'status' => 'approved',
            'created_by' => $this->adminUser->id,
        ]);

        $legal = Legal::create([
            'investment_id' => $investment->id,
            'language' => 'english',
            'legal_number' => 'LEG-COL-26050001',
            'branch_id' => $investment->branch_id,
            'customer_id' => $investment->customer_id,
            'full_name' => 'Jane Doe',
            'name_with_initials' => 'J. Doe',
            'id_type' => 'nic',
            'id_number' => '123456789V',
            'investment_product_id' => $investment->investment_product_id,
            'beneficiary_full_name' => 'Old Beneficiary',
            'beneficiary_id_type' => 'nic',
            'beneficiary_id_number' => '991234567V',
        ]);

        $response = $this->actingAs($this->adminUser, 'api')
            ->putJson('/api/v1/legals/' . $legal->id, [
                'beneficiary_id_number' => 'N9999999', // matches hierarchy user
                'beneficiary_id_type' => 'passport',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['field' => 'beneficiary_id_number']);
    }
}
