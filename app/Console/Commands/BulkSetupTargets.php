<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BulkSetupTargets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'targets:bulk-setup {source?} {target?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Copy target amounts from one month to another';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $sourceKey = $this->argument('source') ?? \Carbon\Carbon::now()->subMonth()->format('Y-m');
        $targetKey = $this->argument('target') ?? \Carbon\Carbon::now()->format('Y-m');

        $this->info("Copying targets from {$sourceKey} to {$targetKey}...");

        $sourceTargets = \App\Models\Target::where('period_key', $sourceKey)->get();

        if ($sourceTargets->isEmpty()) {
            $this->warn("No targets found for source period: {$sourceKey}");
            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($sourceTargets as $sourceTarget) {
            \App\Models\Target::updateOrCreate(
                [
                    'user_id' => $sourceTarget->user_id,
                    'period_key' => $targetKey,
                    'period_type' => $sourceTarget->period_type,
                ],
                [
                    'assigned_by' => $sourceTarget->assigned_by,
                    'target_amount' => $sourceTarget->target_amount,
                    'current_amount' => $sourceTarget->target_amount,
                    'achieved_amount' => 0,
                    'achievement_percentage' => 0,
                    'status' => 'active',
                    'achieved_at' => null,
                ]
            );
            $count++;
        }

        $this->info("Successfully setup {$count} targets for {$targetKey}.");
        return Command::SUCCESS;
    }
}
