<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\InvestmentPayout;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Mockery;
use Tests\TestCase;

class InvestmentPayoutSmsTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $payout;
    protected $smsMock;

    protected function setUp(): void
    {
        parent::setUp();

        // Bypass permission middleware checks
        Gate::before(fn () => true);

        // Mock SMS service
        $this->smsMock = Mockery::mock(SmsService::class);
        $this->app->instance(SmsService::class, $this->smsMock);

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
            'phone_primary' => '0750552243',
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
        $investment = Investment::create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'investment_product_id' => $product->id,
            'investment_amount' => 500000.00,
            'policy_number' => 'POL-001',
            'payment_type' => 'bank',
            'status' => 'approved',
            'created_by' => $this->user->id,
        ]);

        // Create investment payout
        $this->payout = InvestmentPayout::create([
            'investment_id' => $investment->id,
            'scheduled_date' => '2026-05-19',
            'amount' => 5000.00,
            'status' => 'unpaid',
        ]);
    }

    /** @test */
    public function test_it_sends_sms_when_status_updated_to_paid_and_send_sms_is_true()
    {
        $this->smsMock->shouldReceive('sendSms')
            ->once()
            ->with('0750552243', Mockery::on(function ($message) {
                return str_contains($message, 'Dear Jane Doe')
                    && str_contains($message, 'Policy No: POL-001')
                    && str_contains($message, 'Month: May 2026')
                    && str_contains($message, 'Paid Amount: LKR 5,000.00');
            }))
            ->andReturn(true);

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/v1/investment-payouts/{$this->payout->id}/status", [
                'status' => 'paid',
                'send_sms' => true,
                'reference_number' => 'REF-001',
                'remarks' => 'Paid successfully',
            ]);

        $response->assertStatus(200);
        $this->payout->refresh();
        $this->assertEquals('paid', $this->payout->status);
        $this->assertEquals('REF-001', $this->payout->reference_number);
    }

    /** @test */
    public function test_it_does_not_send_sms_when_status_updated_to_paid_and_send_sms_is_false()
    {
        $this->smsMock->shouldNotReceive('sendSms');

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/v1/investment-payouts/{$this->payout->id}/status", [
                'status' => 'paid',
                'send_sms' => false,
                'reference_number' => 'REF-001',
                'remarks' => 'Paid successfully',
            ]);

        $response->assertStatus(200);
        $this->payout->refresh();
        $this->assertEquals('paid', $this->payout->status);
    }

    /** @test */
    public function test_it_does_not_send_sms_when_status_updated_to_hold()
    {
        $this->smsMock->shouldNotReceive('sendSms');

        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/v1/investment-payouts/{$this->payout->id}/status", [
                'status' => 'hold',
                'send_sms' => true,
                'reference_number' => 'REF-001',
                'remarks' => 'Hold payout',
            ]);

        $response->assertStatus(200);
        $this->payout->refresh();
        $this->assertEquals('hold', $this->payout->status);
    }

    /** @test */
    public function test_it_fails_validation_if_send_sms_is_missing_or_not_boolean()
    {
        $response = $this->actingAs($this->user, 'api')
            ->patchJson("/api/v1/investment-payouts/{$this->payout->id}/status", [
                'status' => 'paid',
                'reference_number' => 'REF-001',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('send_sms');

        $response2 = $this->actingAs($this->user, 'api')
            ->patchJson("/api/v1/investment-payouts/{$this->payout->id}/status", [
                'status' => 'paid',
                'send_sms' => 'invalid_boolean',
                'reference_number' => 'REF-001',
            ]);

        $response2->assertStatus(422);
        $response2->assertJsonValidationErrors('send_sms');
    }
}
