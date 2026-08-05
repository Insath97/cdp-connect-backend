<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Investment;
use App\Traits\InvestmentCalculationTrait;

class RecalculatePayouts extends Command
{
    use InvestmentCalculationTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'investments:recalculate-payouts {--id= : Recalculate payouts for a specific investment ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate unpaid payouts for all approved investments or a specific one based on current product rates.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $investmentId = $this->option('id');

        if ($investmentId) {
            $investment = Investment::find($investmentId);
            if (!$investment) {
                $this->error("Investment with ID {$investmentId} not found.");
                return;
            }
            $this->info("Recalculating unpaid payouts for investment ID {$investmentId}...");
            $this->recalculateUnpaidPayouts($investment);
            $this->info("Completed!");
        } else {
            $investments = Investment::where('status', 'approved')->get();
            $this->info("Found {$investments->count()} approved investments. Recalculating unpaid payouts...");
            $bar = $this->output->createProgressBar($investments->count());
            $bar->start();
            foreach ($investments as $investment) {
                $this->recalculateUnpaidPayouts($investment);
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
            $this->info("Completed recalculating payouts for all approved investments.");
        }
    }
}
