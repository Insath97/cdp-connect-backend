<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Target;
use App\Models\Level;
use App\Models\Investment;
use App\Models\Commission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecalculateTargets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:recalculate-targets 
                            {--user_id= : Recalculate for a specific user} 
                            {--period= : The period key (Y-m), defaults to current month}
                            {--commissions : Regenerate commissions for approved investments in the period}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate target achievements and commissions based on actual investment data.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->option('user_id');
        $period = $this->option('period') ?? date('Y-m');

        if ($this->option('commissions')) {
            $this->regenerateCommissions($period);
        }

        if ($userId) {
            $this->recalculateSingleUser($userId, $period);
        } else {
            $this->recalculateAllUsers($period);
        }

        return 0;
    }

    /**
     * Regenerate commissions for all approved investments in a period.
     */
    protected function regenerateCommissions($period)
    {
        $this->info("Regenerating commissions for period {$period}...");

        DB::beginTransaction();
        try {
            // 1. Delete existing commissions for the period to avoid duplicates
            Commission::where('period_key', $period)->delete();

            // 2. Fetch all approved/expired investments for that period
            $investments = Investment::where('target_period_key', $period)
                ->whereIn('status', ['approved', 'expired'])
                ->with(['unitHead', 'investmentProduct'])
                ->get();

            if ($investments->isEmpty()) {
                $this->warn("No approved investments found for period {$period}.");
                DB::commit();
                return;
            }

            $bar = $this->output->createProgressBar($investments->count());
            $bar->start();

            foreach ($investments as $investment) {
                Commission::generateForInvestment($investment);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            DB::commit();
            $this->info("Successfully regenerated commissions for " . $investments->count() . " investments.");
        } catch (\Throwable $th) {
            DB::rollBack();
            $this->error("Failed to regenerate commissions: " . $th->getMessage());
            Log::error("Commission regeneration failed", ['error' => $th->getMessage(), 'period' => $period]);
        }
    }

    /**
     * Recalculate target for a single user.
     */
    protected function recalculateSingleUser($userId, $period)
    {
        $user = User::find($userId);
        if (!$user) {
            $this->error("User not found.");
            return;
        }

        $this->info("Recalculating target for {$user->name} in period {$period}...");
        if (Target::recalculateForUser($userId, $period)) {
            $this->info("Successfully updated.");
        } else {
            $this->error("Failed to update. Does the user have a target for this period?");
        }
    }

    /**
     * Recalculate targets for all users in a period.
     */
    protected function recalculateAllUsers($period)
    {
        $this->info("Recalculating all targets for period {$period}...");

        $targets = Target::where('period_key', $period)->get();
        
        if ($targets->isEmpty()) {
            $this->warn("No targets found for period {$period}.");
            return;
        }

        $bar = $this->output->createProgressBar($targets->count());
        $bar->start();

        foreach ($targets as $target) {
            Target::recalculateForUser($target->user_id, $period);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Finished recalculating " . $targets->count() . " targets.");
    }
}
