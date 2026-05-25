<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\User;
use App\Models\Target;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BeneficiaryClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $branch;
    protected $customer;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();

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
            'user_id' => $this->user->id,
            'period_key' => $targetPeriodKey,
            'target_amount' => 1000000.00,
            'achieved_amount' => 0,
        ]);
    }

    /** @test */
    public function test_validation_requires_id_image_for_adult_beneficiary()
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/investments', [
                'reservation_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'investment_product_id' => $this->product->id,
                'investment_amount' => 50000.00,
                'bank' => 'HNB',
                'payment_type' => 'full_payment',
                'initial_payment' => 50000.00,
                'unit_head_id' => $this->user->id,
                'beneficiary' => [
                    'full_name' => 'Adult Beneficiary',
                    'type' => 'adult',
                    'id_type' => 'nic',
                    'id_number' => '991234567V',
                    'phone_primary' => '0712345678',
                    'relationship' => 'Brother',
                    'share_percentage' => 100.00,
                ]
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['field' => 'beneficiary.id_image']);
    }

    /** @test */
    public function test_validation_requires_child_file_for_child_beneficiary()
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/investments', [
                'reservation_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'investment_product_id' => $this->product->id,
                'investment_amount' => 50000.00,
                'bank' => 'HNB',
                'payment_type' => 'full_payment',
                'initial_payment' => 50000.00,
                'unit_head_id' => $this->user->id,
                'beneficiary' => [
                    'full_name' => 'Child Beneficiary',
                    'type' => 'child',
                    'id_type' => 'other',
                    'id_number' => '202012345678',
                    'phone_primary' => '0712345678',
                    'relationship' => 'Son',
                    'share_percentage' => 100.00,
                ]
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['field' => 'beneficiary.child_file']);
    }

    /** @test */
    public function test_successful_investment_creation_with_adult_beneficiary_id_image()
    {
        $idImage = UploadedFile::fake()->image('id_image.jpg');
        $paymentProof = UploadedFile::fake()->image('proof.jpg');

        $response = $this->actingAs($this->user, 'api')
            ->post('/api/v1/investments', [
                'reservation_date' => now()->format('Y-m-d'),
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'investment_product_id' => $this->product->id,
                'investment_amount' => 50000.00,
                'bank' => 'HNB',
                'payment_type' => 'full_payment',
                'payment_proof' => $paymentProof,
                'initial_payment' => 50000.00,
                'unit_head_id' => $this->user->id,
                'beneficiary' => [
                    'full_name' => 'Adult Beneficiary',
                    'type' => 'adult',
                    'id_type' => 'nic',
                    'id_number' => '991234567V',
                    'phone_primary' => '0712345678',
                    'relationship' => 'Brother',
                    'share_percentage' => 100.00,
                    'id_image' => $idImage,
                ]
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('beneficiaries', [
            'full_name' => 'Adult Beneficiary',
            'type' => 'adult',
        ]);

        $beneficiary = \App\Models\Beneficiary::where('full_name', 'Adult Beneficiary')->first();
        $this->assertNotNull($beneficiary->id_image);
        $this->assertFileExists(public_path($beneficiary->id_image));

        // Clean up
        if (file_exists(public_path($beneficiary->id_image))) {
            unlink(public_path($beneficiary->id_image));
        }
        if (file_exists(public_path($beneficiary->investment->payment_proof))) {
            unlink(public_path($beneficiary->investment->payment_proof));
        }
    }
}
