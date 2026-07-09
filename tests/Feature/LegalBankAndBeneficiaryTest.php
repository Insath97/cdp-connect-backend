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

    /** @test */
    public function test_it_strips_metadata_and_saves_successfully_with_long_input()
    {
        $metadataJson = '|||METADATA:{"customer_name":"Jane Doe","customer_address":"123 Main St"}';
        $longWitnessAddress = 'Hatton ' . $metadataJson;

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/legals', [
                'investment_id' => $this->investment->id,
                'language' => 'tamil',
                'full_name' => 'Jane Doe',
                'name_with_initials' => 'J. Doe',
                'address_line_1' => '123 Main St',
                'witness_02_name' => 'Witness Name',
                'witness_02_nic' => '987654321V',
                'witness_02_address' => $longWitnessAddress,
            ]);

        $response->assertStatus(201);

        $legal = Legal::first();
        $this->assertNotNull($legal);
        // Assert the metadata suffix was stripped and only the base address is stored
        $this->assertEquals('Hatton', $legal->witness_02_address);
    }

    /** @test */
    public function test_it_validates_and_stores_new_tamil_and_sinhala_translation_fields()
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/legals', [
                'investment_id' => $this->investment->id,
                'language' => 'tamil',
                'full_name' => 'Jane Doe',
                'name_with_initials' => 'J. Doe',
                'address_line_1' => '123 Main St',
                'business_entered_date' => '2026-06-17',
                'completed_date' => '2027-06-17',
                'execution_year' => '2026',
            ]);

        $response->assertStatus(201);

        $legal = Legal::first();
        $this->assertNotNull($legal);
        $this->assertEquals('2026-06-17', $legal->business_entered_date);
        $this->assertEquals('2027-06-17', $legal->completed_date);
        $this->assertEquals('2026', $legal->execution_year);
    }

    /** @test */
    public function test_it_validates_and_stores_new_tamil_and_english_translation_fields()
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/legals', [
                'investment_id' => $this->investment->id,
                'language' => 'english',
                'amount_in_words' => 'Five Hundred Thousand Rupees Only',
                'plan' => '12 Months',
                'monthly_profit' => 'Fifteen Thousand Rupees Only',
                'monthly_profit_day' => '17th',
            ]);

        $response->assertStatus(201);

        $legal = Legal::first();
        $this->assertNotNull($legal);
        $this->assertEquals('Five Hundred Thousand Rupees Only', $legal->amount_in_words);
        $this->assertEquals('12 Months', $legal->plan);
        $this->assertEquals('Fifteen Thousand Rupees Only', $legal->monthly_profit);
        $this->assertEquals('17th', $legal->monthly_profit_day);
    }

    /** @test */
    public function test_it_validates_and_stores_new_tamil_and_sinhala_word_breakdown_fields()
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/legals', [
                'investment_id' => $this->investment->id,
                'language' => 'tamil',
                'full_name' => 'Jane Doe',
                'name_with_initials' => 'J. Doe',
                'address_line_1' => '123 Main St',
                'month_6_breakdown_in_words' => 'tamil month 6 words',
                'year_1_breakdown_in_words' => 'tamil year 1 words',
                'year_2_breakdown_in_words' => 'tamil year 2 words',
                'year_3_breakdown_in_words' => 'tamil year 3 words',
                'year_4_breakdown_in_words' => 'tamil year 4 words',
                'year_5_breakdown_in_words' => 'tamil year 5 words',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.month_6_breakdown_in_words', 'tamil month 6 words');
        $response->assertJsonPath('data.year_1_breakdown_in_words', 'tamil year 1 words');

        $legal = Legal::first();
        $this->assertNotNull($legal);
        $this->assertEquals('tamil month 6 words', $legal->month_6_breakdown_in_words);
        $this->assertEquals('tamil year 5 words', $legal->year_5_breakdown_in_words);
    }

    /** @test */
    public function test_it_does_not_save_sinhala_tamil_word_breakdown_fields_for_english_language()
    {
        // First create a tamil legal record with breakdowns in words
        $legal = Legal::create([
            'investment_id' => $this->investment->id,
            'language' => 'english',
            'legal_number' => 'LEG-COL-26050009',
            'branch_id' => $this->investment->branch_id,
            'customer_id' => $this->investment->customer_id,
            'full_name' => 'Jane Doe',
            'name_with_initials' => 'J. Doe',
            'id_type' => 'nic',
            'id_number' => '123456789V',
            'investment_product_id' => $this->investment->investment_product_id,
            'month_6_breakdown_in_words' => 'english month 6 words',
        ]);

        // When updating, since the language is english, it should unset these word breakdown fields
        $response = $this->actingAs($this->user, 'api')
            ->putJson('/api/v1/legals/' . $legal->id, [
                'month_6_breakdown_in_words' => 'updated month 6 words',
            ]);

        $response->assertStatus(200);
        $legal->refresh();
        // Since language is english, updating should not change it
        $this->assertNotEquals('updated month 6 words', $legal->month_6_breakdown_in_words);
    }
}

