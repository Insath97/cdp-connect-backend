<?php

namespace App\Console\Commands;

use App\Models\Investment;
use App\Jobs\SendSameDayExpirySmsJob;
use App\Traits\ActivityLogTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendSameDayExpirySmsCommand extends Command
{
    use ActivityLogTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-same-day-expiry-sms';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send SMS notifications to investors on the exact day of investment maturity';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $targetMaturityDate = Carbon::now()->toDateString();
        $this->info("Scanning for investments maturing TODAY ({$targetMaturityDate})...");

        try {
            // Maturity Date SQL expression: reservation_date + duration_months
            $maturityDateSql = "DATE_ADD(investments.reservation_date, INTERVAL investment_products.duration_months MONTH)";

            // Fetch approved investments where computed maturity date equals TODAY
            $investments = Investment::with(['customer', 'investmentProduct'])
                ->join('investment_products', 'investments.investment_product_id', '=', 'investment_products.id')
                ->select('investments.*')
                ->where('investments.status', 'approved')
                ->whereRaw("DATE({$maturityDateSql}) <= ?", [$targetMaturityDate])
                ->where(function ($q) {
                    $q->whereNull('same_day_expiry_sms_sent_at')
                      ->orWhere('same_day_expiry_sms_sent_at', '<', Carbon::now()->subDays(30));
                })
                ->get();

            if ($investments->isEmpty()) {
                $this->info("No investments found maturing today on {$targetMaturityDate}.");
                $this->logActivity('Info', 'SameDayExpirySms', "No investments found maturing today for date {$targetMaturityDate}", [
                    'target_date' => $targetMaturityDate
                ]);
                return 0;
            }

            $count = 0;
            foreach ($investments as $investment) {
                SendSameDayExpirySmsJob::dispatch($investment->id);
                $count++;
                $this->info("Dispatched same-day maturity SMS job for Investment #{$investment->id} (Policy: {$investment->policy_number})");

                $this->logActivity('Dispatch', 'SameDayExpirySms', "Dispatched same-day maturity SMS job for Policy #{$investment->policy_number}", [
                    'investment_id' => $investment->id,
                    'policy_number' => $investment->policy_number,
                    'customer_id' => $investment->customer_id
                ]);
            }

            $this->logActivity('Info', 'SameDayExpirySms', "Completed dispatching {$count} same-day maturity SMS job(s) for date {$targetMaturityDate}", [
                'count' => $count,
                'target_date' => $targetMaturityDate
            ]);

            $this->info("Successfully dispatched {$count} same-day maturity SMS job(s).");

            return 0;

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'SameDayExpirySms', "Failed to execute same-day maturity SMS command: " . $th->getMessage(), [
                'error' => $th->getMessage(),
                'target_date' => $targetMaturityDate
            ]);

            $this->error("Failed to execute same-day maturity SMS command: " . $th->getMessage());
            return 1;
        }
    }
}
