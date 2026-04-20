<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Target;
use App\Models\Level;

class RecalculateTargets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:recalculate-targets {--user_id= : Recalculate for a specific user} {--period= : The period key (Y-m), defaults to current month}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate target achievements based on actual investment data in the hierarchy.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->option('user_id');
        $period = $this->option('period') ?? date('Y-m');

        if ($userId) {
            $user = User::find($userId);
            if (!$user) {
                $this->error("User not found.");
                return 1;
            }

            $this->info("Recalculating target for {$user->name} in period {$period}...");
            if (Target::recalculateForUser($userId, $period)) {
                $this->info("Successfully updated.");
            } else {
                $this->error("Failed to update. Does the user have a target for this period?");
            }
        } else {
            $this->info("Recalculating all targets for period {$period}...");
            
            // Recalculate in order of levels (bottom to top for safety, though recalculateForUser is independent)
            $targets = Target::where('period_key', $period)->get();
            $bar = $this->output->createProgressBar($targets->count());

            foreach ($targets as $target) {
                Target::recalculateForUser($target->user_id, $period);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->info("Finished recalculating " . $targets->count() . " targets.");
        }

        return 0;
    }
}
