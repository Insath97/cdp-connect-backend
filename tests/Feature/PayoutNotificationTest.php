<?php

namespace Tests\Feature;

use App\Models\Investment;
use App\Models\Customer;
use App\Models\User;
use App\Models\InvestmentProduct;
use App\Services\SmsService;
use App\Mail\MonthlyPayoutMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class PayoutNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_payout_notifications_only_after_1_month_has_passed()
    {
        Mail::fake();
        $smsMock = Mockery::mock(SmsService::class);
        $this->app->instance(SmsService::class, $smsMock);

        // Set "Today" to 2026-04-19
        $today = Carbon::parse('2026-04-19');
        Carbon::setTestNow($today);

        // Setup common data
        $product = InvestmentProduct::create([
            'name' => 'Test Product', 
            'roi_percentage' => 12, 
            'duration_months' => 12, 
            'code' => 'TEST'
        ]);
        
        $user = User::create([
            'name' => 'John User', 
            'email' => 'john@example.com', 
            'password' => bcrypt('password')
        ]);
        
        $customer = Customer::create([
            'full_name' => 'John Doe', 
            'phone_primary' => '0750552243', 
            'customer_id' => $user->id,
            'customer_code' => 'CUST001'
        ]);

        // 1. Investment reserved today (2026-04-19) -> NOT NOTIFIED
        Investment::create([
            'policy_number' => 'POL001',
            'status' => 'approved',
            'reservation_date' => '2026-04-19',
            'investment_amount' => 1000000,
            'investment_product_id' => $product->id,
            'customer_id' => $customer->id,
        ]);

        // 2. Investment reserved 1 month ago (2026-03-19) -> NOTIFIED
        Investment::create([
            'policy_number' => 'POL002',
            'status' => 'approved',
            'reservation_date' => '2026-03-19',
            'investment_amount' => 1000000,
            'investment_product_id' => $product->id,
            'customer_id' => $customer->id,
        ]);

        // 3. Investment reserved 2 months ago (2026-02-19) -> NOTIFIED
        Investment::create([
            'policy_number' => 'POL003',
            'status' => 'approved',
            'reservation_date' => '2026-02-19',
            'investment_amount' => 1000000,
            'investment_product_id' => $product->id,
            'customer_id' => $customer->id,
        ]);
        
        // 4. Investment with explicit monthly_payment_date = today -> NOTIFIED
        Investment::create([
            'policy_number' => 'POL004',
            'status' => 'approved',
            'reservation_date' => '2026-04-19',
            'monthly_payment_date' => '2026-04-19',
            'investment_amount' => 1000000,
            'investment_product_id' => $product->id,
            'customer_id' => $customer->id,
        ]);

        // Expecting 3 SMS sends (POL002, POL003, POL004)
        $smsMock->shouldReceive('sendSms')->times(3);

        $this->artisan('app:send-monthly-payout-notifications')
             ->assertExitCode(0);

        Mail::assertSent(MonthlyPayoutMail::class, 3);
        
        Carbon::setTestNow(); // Reset
    }
}
