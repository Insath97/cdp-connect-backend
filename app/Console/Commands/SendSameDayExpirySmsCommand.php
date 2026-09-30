<?php

namespace App\Console\Commands;

use App\Jobs\SendSameDayExpirySmsJob;
use App\Models\Investment;
use App\Traits\ActivityLogTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendSameDayExpirySmsCommand extends Command
{
    use ActivityLogTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-same-day-expiry-sms {--queue : Dispatch to background queue instead of executing synchronously} {--date= : Target date to evaluate maturity (YYYY-MM-DD)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically expire maturing investments and send maturity SMS notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $targetDateInput = $this->option('date');
        $targetMaturityDate = $targetDateInput
            ? Carbon::parse($targetDateInput)->toDateString()
            : Carbon::now('Asia/Colombo')->toDateString();

        $isQueueMode = (bool) $this->option('queue');

        $this->info("Scanning for investments maturing on or before {$targetMaturityDate} (Mode: ".($isQueueMode ? 'Queue' : 'Sync').')...');

        try {
            $targetCarbon = Carbon::parse($targetMaturityDate)->startOfDay();

            // Fetch approved investments and evaluate maturity in PHP so the
            // expression stays portable across MySQL and SQLite.
            $investments = Investment::with('investmentProduct:id,duration_months')
                ->where('status', 'approved')
                ->get(['id', 'reservation_date', 'investment_product_id', 'policy_number'])
                ->filter(fn (Investment $investment) => ($maturity = $this->maturityDate($investment)) !== null && $maturity->lte($targetCarbon))
                ->values();

            if ($investments->isEmpty()) {
                $this->info("No approved investments found maturing on or before {$targetMaturityDate}.");
                $this->logActivity('Info', 'SameDayExpirySms', "No investments found maturing on or before {$targetMaturityDate}", [
                    'target_date' => $targetMaturityDate,
                ]);

                return 0;
            }

            $count = 0;
            $successCount = 0;

            foreach ($investments as $investment) {
                $count++;
                try {
                    if ($isQueueMode) {
                        SendSameDayExpirySmsJob::dispatch($investment->id);
                        $this->info("[{$count}/{$investments->count()}] Queued maturity job for Investment #{$investment->id} (Policy: {$investment->policy_number})");
                    } else {
                        // Synchronous execution: runs immediately right here inside the command!
                        SendSameDayExpirySmsJob::dispatchSync($investment->id);
                        $this->info("[{$count}/{$investments->count()}] Successfully processed Investment #{$investment->id} (Policy: {$investment->policy_number})");
                    }

                    $successCount++;

                    $this->logActivity('Process', 'SameDayExpirySms', "Processed same-day maturity for Policy #{$investment->policy_number}", [
                        'investment_id' => $investment->id,
                        'policy_number' => $investment->policy_number,
                        'customer_id' => $investment->customer_id,
                        'mode' => $isQueueMode ? 'queue' : 'sync',
                    ]);
                } catch (\Throwable $itemError) {
                    $this->error("Failed to process Investment #{$investment->id}: ".$itemError->getMessage());
                    $this->logActivity('Error', 'SameDayExpirySms', "Error processing Investment #{$investment->id}: ".$itemError->getMessage(), [
                        'investment_id' => $investment->id,
                        'error' => $itemError->getMessage(),
                    ]);
                }
            }

            $this->logActivity('Info', 'SameDayExpirySms', "Completed processing {$successCount}/{$count} investment(s) for target date {$targetMaturityDate}", [
                'total' => $count,
                'success' => $successCount,
                'target_date' => $targetMaturityDate,
            ]);

            $this->info("Completed: {$successCount} of {$count} maturing investment(s) processed.");

            return 0;

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'SameDayExpirySms', 'Failed to execute same-day maturity SMS command: '.$th->getMessage(), [
                'error' => $th->getMessage(),
                'target_date' => $targetMaturityDate,
            ]);

            $this->error('Failed to execute same-day maturity SMS command: '.$th->getMessage());

            return 1;
        }
    }

    /**
     * Compute maturity date as reservation_date + product duration in months.
     */
    protected function maturityDate(Investment $investment): ?Carbon
    {
        $duration = $investment->investmentProduct?->duration_months;

        if (! $investment->reservation_date || ! $duration) {
            return null;
        }

        return Carbon::parse($investment->reservation_date)->addMonths((int) $duration)->startOfDay();
    }
}
