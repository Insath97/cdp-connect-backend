<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\ExpiredInvestment;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\User;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class SendSameDayExpirySmsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $customer;
    protected $branch;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

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
        $this->product = InvestmentProduct::create([
            'name' => '1 Year Plan',
            'code' => '1YP',
            'duration_months' => 12,
            'roi_percentage' => 18.00,
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'full_name' => 'Test Investor',
            'name_with_initials' => 'T. Investor',
            'customer_code' => 'CUST-EXP-001',
            'id_type' => 'nic',
            'id_number' => '199012345678',
            'date_of_birth' => '1990-01-01',
            'phone_primary' => '0750552243',
            'email' => 'investor@test.com',
            'branch_id' => $this->branch->id,
            'created_by' => $this->user->id,
        ]);
    }

    /** @test */
    public function test_it_automatically_expires_maturing_investments_and_sends_sms()
    {
        $mockSms = Mockery::mock(SmsService::class);
        $mockSms->shouldReceive('sendSms')
            ->once()
            ->andReturn(true);
        $this->app->instance(SmsService::class, $mockSms);

        $investment = Investment::create([
            'policy_number' => 'POL-TEST-001',
            'application_number' => 'APP-001',
            'sales_code' => 'SC-001',
            'reservation_date' => Carbon::now()->subMonths(12)->toDateString(),
            'target_period_key' => '2025-01',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 100000.00,
            'status' => 'approved',
            'created_by' => $this->user->id,
            'unit_head_id' => $this->user->id,
            'payment_proof' => 'proof.png',
        ]);

        $exitCode = Artisan::call('app:send-same-day-expiry-sms');
        $this->assertEquals(0, $exitCode);

        $investment->refresh();
        $this->assertEquals('expired', $investment->status);
        $this->assertNotNull($investment->same_day_expiry_sms_sent_at);

        $this->assertDatabaseHas('expired_investments', [
            'investment_id' => $investment->id,
            'customer_id' => $this->customer->id,
            'status' => 'unpaid',
            'investment_amount' => 100000.00,
        ]);
    }

    /** @test */
    public function test_it_still_marks_investment_expired_when_sms_fails()
    {
        $mockSms = Mockery::mock(SmsService::class);
        $mockSms->shouldReceive('sendSms')
            ->once()
            ->andReturn(false);
        $this->app->instance(SmsService::class, $mockSms);

        $investment = Investment::create([
            'policy_number' => 'POL-TEST-002',
            'application_number' => 'APP-002',
            'sales_code' => 'SC-002',
            'reservation_date' => Carbon::now()->subMonths(12)->toDateString(),
            'target_period_key' => '2025-01',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 200000.00,
            'status' => 'approved',
            'created_by' => $this->user->id,
            'unit_head_id' => $this->user->id,
            'payment_proof' => 'proof.png',
        ]);

        $exitCode = Artisan::call('app:send-same-day-expiry-sms');
        $this->assertEquals(0, $exitCode);

        $investment->refresh();
        $this->assertEquals('expired', $investment->status);
        $this->assertNull($investment->same_day_expiry_sms_sent_at);

        $this->assertDatabaseHas('expired_investments', [
            'investment_id' => $investment->id,
            'status' => 'unpaid',
            'investment_amount' => 200000.00,
        ]);
    }

    /** @test */
    public function test_it_does_not_expire_investments_that_have_not_reached_maturity()
    {
        $investment = Investment::create([
            'policy_number' => 'POL-TEST-003',
            'application_number' => 'APP-003',
            'sales_code' => 'SC-003',
            'reservation_date' => Carbon::now()->subMonths(6)->toDateString(), // 6 months into a 12 month plan
            'target_period_key' => '2025-06',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'investment_product_id' => $this->product->id,
            'investment_amount' => 300000.00,
            'status' => 'approved',
            'created_by' => $this->user->id,
            'unit_head_id' => $this->user->id,
            'payment_proof' => 'proof.png',
        ]);

        $exitCode = Artisan::call('app:send-same-day-expiry-sms');
        $this->assertEquals(0, $exitCode);

        $investment->refresh();
        $this->assertEquals('approved', $investment->status);
        $this->assertDatabaseMissing('expired_investments', [
            'investment_id' => $investment->id,
        ]);
    }
}
