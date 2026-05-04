<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Investment;
use App\Models\InvestmentPayout;
use App\Traits\InvestmentCalculationTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GenerateLegacyPayoutSchedules extends Command
{
    use InvestmentCalculationTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'investments:generate-legacy-payouts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate payout schedules for already approved investments that lack them.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting payout schedule generation for legacy approved investments...');

        $investments = Investment::where('status', 'approved')
            ->whereDoesntHave('payouts')
            ->with('investmentProduct.annualRates')
            ->get();

        if ($investments->isEmpty()) {
            $this->info('No legacy approved investments without payouts found.');
            return;
        }

        $this->info("Found {$investments->count()} investments to process.");

        $bar = $this->output->createProgressBar($investments->count());
        $bar->start();

        foreach ($investments as $investment) {
            $this->generatePayoutSchedule($investment);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Legacy payout schedule generation completed.');
    }

    /**
     * Logic copied from InvestmentController (to be kept in sync or moved to a service)
     */
    protected function generatePayoutSchedule(Investment $investment)
    {
        try {
            $product = $investment->investmentProduct;
            if (!$product) return;

            $calculations = $this->calculateInvestmentROI((float)$investment->investment_amount, $product);
            
            $startDate = $investment->reservation_date ?? $investment->created_at;
            $payoutDate = Carbon::parse($startDate);

            $payouts = [];
            foreach ($calculations['yearly_breakdown'] ?? [] as $yearData) {
                $monthlyPayout = $yearData['monthly_payout'];
                $monthsInYear = $yearData['duration_months'];

                for ($i = 0; $i < $monthsInYear; $i++) {
                    $payoutDate->addMonth();
                    $payouts[] = [
                        'investment_id' => $investment->id,
                        'scheduled_date' => $payoutDate->format('Y-m-d'),
                        'amount' => round($monthlyPayout, 2),
                        'status' => 'unpaid',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if (!empty($payouts)) {
                InvestmentPayout::insert($payouts);
            }

        } catch (\Throwable $th) {
            $this->error("\nFailed for Investment ID {$investment->id}: {$th->getMessage()}");
            Log::error('Legacy payout generation failed', [
                'investment_id' => $investment->id,
                'error' => $th->getMessage()
            ]);
        }
    }
}
