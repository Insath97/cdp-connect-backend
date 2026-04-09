<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\InvestmentController;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\User;
use App\Models\Commission;
use Tests\TestCase;
use Mockery;

class CommissionCalculationTest extends TestCase
{
    /** @test */
    public function test_it_calculates_parent_commission_based_on_unit_head_commission()
    {
        // 1. Setup Mock Data
        $amount = 100000;
        $unitHeadPct = 10;
        $parentPct = 20;

        // Mock Investment Product
        $product = Mockery::mock(InvestmentProduct::class);
        $product->unit_head_commission_pct = $unitHeadPct;
        $product->parent_commission_pct = $parentPct;

        // Mock Users
        $parentUser = Mockery::mock(User::class);
        $parentUser->id = 2;

        $unitHeadUser = Mockery::mock(User::class);
        $unitHeadUser->id = 1;
        $unitHeadUser->parent_user_id = 2;

        // Mock Investment
        $investment = Mockery::mock(Investment::class);
        $investment->id = 101;
        $investment->investment_amount = $amount;
        $investment->unit_head_id = 1;
        $investment->target_period_key = '2024-04';
        $investment->investmentProduct = $product;
        $investment->unitHead = $unitHeadUser;

        // Expectations for Commission::create
        $expectedUhAmount = ($amount * $unitHeadPct) / 100; // 10,000
        $expectedParentAmount = ($expectedUhAmount * $parentPct) / 100; // 2,000

        $commissionMock = Mockery::mock('alias:' . Commission::class);
        
        $commissionMock->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($args) use ($expectedUhAmount) {
                return $args['tier'] === 'unit_head' && $args['commission_amount'] == $expectedUhAmount;
            }));

        $commissionMock->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($args) use ($expectedParentAmount) {
                return $args['tier'] === 'parent' && $args['commission_amount'] == $expectedParentAmount;
            }));

        // 2. Invoke Logic
        $controller = new class extends InvestmentController {
            public function callProcessCommissions($investment) {
                $this->processCommissions($investment);
            }
        };

        $controller->callProcessCommissions($investment);

        // 3. Verify
        Mockery::close();
        $this->assertTrue(true);
    }
}
