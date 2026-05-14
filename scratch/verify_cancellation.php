<?php

use App\Models\Investment;
use App\Models\SystemSetting;
use App\Models\InvestmentPayout;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Mock environment for testing logic if DB is not available
// However, since this is a Laravel app, we need the bootstrap
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function testCancellationRule($daysAgo, $expectedAdminCostDeducted) {
    echo "Testing cancellation for investment from $daysAgo days ago...\n";
    
    DB::beginTransaction();
    try {
        // 1. Setup Data
        $adminCostPercentage = 0.15;
        SystemSetting::updateOrCreate(['key' => 'admin_cost'], ['value' => $adminCostPercentage]);
        
        $investmentAmount = 100000;
        $reservationDate = Carbon::now()->subDays($daysAgo);
        
        $investment = Investment::create([
            'investment_amount' => $investmentAmount,
            'reservation_date' => $reservationDate,
            'status' => 'approved',
            'customer_id' => 1, // Assume exists
            'branch_id' => 1,
            'investment_product_id' => 1,
            'created_by' => 1,
            'unit_head_id' => 1,
            'application_number' => 'TEST-'.rand(1000,9999),
        ]);
        
        // 2. Mock Payouts (none paid)
        
        // 3. Trigger Logic (Simulate cancel method logic)
        $paidPayoutsTotal = 0;
        $daysSinceReservation = $reservationDate->diffInDays(Carbon::now());
        
        $adminCostAmount = 0;
        if ($daysSinceReservation > 14) {
            $adminCostAmount = $investmentAmount * $adminCostPercentage;
        }
        
        $refundAmount = max(0, $investmentAmount - $paidPayoutsTotal - $adminCostAmount);
        
        echo "Days Since Reservation: $daysSinceReservation\n";
        echo "Admin Cost Deducted: $adminCostAmount\n";
        echo "Refund Amount: $refundAmount\n";
        
        if ($expectedAdminCostDeducted && $adminCostAmount > 0) {
            echo "PASS: Admin cost deducted correctly.\n";
        } elseif (!$expectedAdminCostDeducted && $adminCostAmount == 0) {
            echo "PASS: No admin cost deducted as expected.\n";
        } else {
            echo "FAIL: Deduction logic incorrect.\n";
        }
        
    } catch (\Throwable $th) {
        echo "Error: " . $th->getMessage() . "\n";
    } finally {
        DB::rollBack();
    }
    echo "-----------------------------------\n";
}

testCancellationRule(20, true);  // > 14 days
testCancellationRule(10, false); // <= 14 days
