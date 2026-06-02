<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBankDetail;
use App\Models\Beneficiary;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\Legal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class LegalBankAndBeneficiaryTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $investment;
    protected $bankDetail;
    protected $beneficiary;

    protected function setUp(): void
    {
        parent::setUp();

        // Bypass permission middleware checks
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

        // Create a branch
        $branch = Branch::create([
            'name' => 'Colombo Branch',
            'code' => 'COL',
            'is_active' => true,
        ]);

        // Create a customer
        $customer = Customer::create([
            'full_name' => 'Jane Doe',
            'name_with_initials' => 'J. Doe',
            'customer_code' => 'CUST001',
            'id_type' => 'nic',
            'id_number' => '123456789V',
            'email' => 'jane@example.com',
        ]);

        // Create bank details
        $this->bankDetail = CustomerBankDetail::create([
            'customer_id' => $customer->id,
            'bank_name' => 'Bank of Ceylon',
            'branch_name' => 'Kollupitiya',
            'account_number' => '987654321',
            'payment_method' => 'bank_transfer',
        ]);

        // Create beneficiary details
        $this->beneficiary = Beneficiary::create([
            'customer_id' => $customer->id,
            'full_name' => 'Bob Doe',
            'id_type' => 'nic',
            'id_number' => '987654321V',
            'phone_primary' => '0712345678',
            'relationship' => 'Son',
            'share_percentage' => 100.00,
        ]);

        // Create investment product
        $product = InvestmentProduct::create([
            'name' => 'Savings Plan',
            'code' => 'SP001',
            'roi_percentage' => 10.00,
            'duration_months' => 12,
            'is_active' => true,
        ]);

        // Create investment
        $this->investment = Investment::create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'investment_product_id' => $product->id,
            'customer_bank_detail_id' => $this->bankDetail->id,
            'beneficiary_id' => $this->beneficiary->id,
            'investment_amount' => 500000.00,
            'payment_type' => 'bank',
            'status' => 'approved',
            'created_by' => $this->user->id,
        ]);
    }

    /** @test */
    public function test_it_creates_legal_agreement_and_automatically_populates_bank_and_beneficiary_details()
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/legals', [
                'investment_id' => $this->investment->id,
                'language' => 'english',
            ]);

        $response->assertStatus(201);

        $legal = Legal::first();
        $this->assertNotNull($legal);
        
        // Assert bank details populated from the investment
        $this->assertEquals($this->bankDetail->bank_name, $legal->bank_name);
        $this->assertEquals($this->bankDetail->branch_name, $legal->branch_name);
        $this->assertEquals($this->bankDetail->account_number, $legal->account_number);

        // Assert beneficiary details populated from the investment
        $this->assertEquals($this->beneficiary->full_name, $legal->beneficiary_full_name);
        $this->assertEquals($this->beneficiary->id_type, $legal->beneficiary_id_type);
        $this->assertEquals($this->beneficiary->id_number, $legal->beneficiary_id_number);
        $this->assertEquals($this->beneficiary->phone_primary, $legal->beneficiary_phone_primary);
        $this->assertEquals($this->beneficiary->relationship, $legal->beneficiary_relationship);
        $this->assertEquals($this->beneficiary->share_percentage, $legal->beneficiary_share_percentage);
    }

    /** @test */
    public function test_it_creates_legal_agreement_with_explicit_bank_and_beneficiary_details()
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/legals', [
                'investment_id' => $this->investment->id,
                'language' => 'english',
                'bank_name' => 'Commercial Bank',
                'branch_name' => 'Galle Face',
                'account_number' => '1122334455',
                'beneficiary_full_name' => 'Alice Doe',
                'beneficiary_id_type' => 'passport',
                'beneficiary_id_number' => 'N1234567',
                'beneficiary_phone_primary' => '0777654321',
                'beneficiary_relationship' => 'Daughter',
                'beneficiary_share_percentage' => 50.00,
            ]);

        $response->assertStatus(201);

        $legal = Legal::first();
        $this->assertNotNull($legal);

        // Assert bank details populated from explicitly provided fields
        $this->assertEquals('Commercial Bank', $legal->bank_name);
        $this->assertEquals('Galle Face', $legal->branch_name);
        $this->assertEquals('1122334455', $legal->account_number);

        // Assert beneficiary details populated from explicitly provided fields
        $this->assertEquals('Alice Doe', $legal->beneficiary_full_name);
        $this->assertEquals('passport', $legal->beneficiary_id_type);
        $this->assertEquals('N1234567', $legal->beneficiary_id_number);
        $this->assertEquals('0777654321', $legal->beneficiary_phone_primary);
        $this->assertEquals('Daughter', $legal->beneficiary_relationship);
        $this->assertEquals(50.00, $legal->beneficiary_share_percentage);
    }

    /** @test */
    public function test_it_updates_legal_agreement_bank_and_beneficiary_details()
    {
        $legal = Legal::create([
            'investment_id' => $this->investment->id,
            'language' => 'english',
            'legal_number' => 'LEG-COL-26050001',
            'branch_id' => $this->investment->branch_id,
            'customer_id' => $this->investment->customer_id,
            'full_name' => 'Jane Doe',
            'name_with_initials' => 'J. Doe',
            'id_type' => 'nic',
            'id_number' => '123456789V',
            'investment_product_id' => $this->investment->investment_product_id,
            'bank_name' => 'Old Bank',
            'beneficiary_full_name' => 'Old Beneficiary',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->putJson('/api/v1/legals/' . $legal->id, [
                'bank_name' => 'Updated Bank',
                'beneficiary_full_name' => 'Updated Beneficiary',
            ]);

        $response->assertStatus(200);

        $legal->refresh();
        $this->assertEquals('Updated Bank', $legal->bank_name);
        $this->assertEquals('Updated Beneficiary', $legal->beneficiary_full_name);
    }

    /** @test */
    public function test_it_creates_legal_agreement_with_sinhala_language()
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/legals', [
                'investment_id' => $this->investment->id,
                'language' => 'sinhala',
            ]);

        $response->assertStatus(201);

        $legal = Legal::where('language', 'sinhala')->first();
        $this->assertNotNull($legal);
        $this->assertEquals('sinhala', $legal->language);
    }

    /** @test */
    public function test_it_lists_legal_agreements_with_all_dynamic_details()
    {
        // Create a legal agreement
        $legal = Legal::create([
            'investment_id' => $this->investment->id,
            'language' => 'english',
            'legal_number' => 'LEG-COL-26050002',
            'branch_id' => $this->investment->branch_id,
            'customer_id' => $this->investment->customer_id,
            'full_name' => 'Jane Doe',
            'name_with_initials' => 'J. Doe',
            'id_type' => 'nic',
            'id_number' => '123456789V',
            'investment_product_id' => $this->investment->investment_product_id,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/legals');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'data' => [
                    '*' => [
                        'id',
                        'policy_number',
                        'application_number',
                        'investment_amount',
                        'agent_name',
                        'yearly_breakdown',
                        'monthly_return',
                        'annual_return',
                        'maturity_amount',
                        'month_6_breakdown',
                        'year_1_breakdown',
                        'year_2_breakdown',
                        'year_3_breakdown',
                        'year_4_breakdown',
                        'year_5_breakdown',
                        'customer',
                        'branch',
                        'bank_detail',
                        'beneficiary',
                        'investment_product'
                    ]
                ]
            ]
        ]);

        $data = $response->json('data.data');
        $item = collect($data)->firstWhere('id', $this->investment->id);
        
        $this->assertNotNull($item);
        $this->assertEquals($this->user->name, $item['agent_name']);
        $this->assertNotEmpty($item['yearly_breakdown']);
    }

    /** @test */
    public function test_it_lists_investments_for_legals()
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/legals-investments');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'data' => [
                    '*' => [
                        'id',
                        'policy_number',
                        'investment_amount',
                        'status',
                        'agent_name',
                        'yearly_breakdown',
                        'monthly_return',
                        'annual_return',
                        'maturity_amount',
                        'customer',
                        'branch',
                        'beneficiary',
                        'bank_detail',
                        'investment_product',
                        'unit_head'
                    ]
                ]
            ]
        ]);
    }

    /** @test */
    public function test_it_shows_single_investment_for_legals()
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/legals-investments/' . $this->investment->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'id',
                'policy_number',
                'investment_amount',
                'status',
                'agent_name',
                'yearly_breakdown',
                'monthly_return',
                'annual_return',
                'maturity_amount',
                'customer',
                'branch',
                'beneficiary',
                'bank_detail',
                'investment_product',
                'unit_head'
            ]
        ]);
    }
}
