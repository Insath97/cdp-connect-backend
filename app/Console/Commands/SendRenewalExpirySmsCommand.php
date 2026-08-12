<?php

namespace App\Console\Commands;

use App\Models\Investment;
use App\Jobs\SendRenewalExpirySmsJob;
use App\Traits\ActivityLogTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendRenewalExpirySmsCommand extends Command
{
    use ActivityLogTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-renewal-expiry-sms';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send pre-reminder SMS notifications to investors 7 days before investment maturity';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $targetMaturityDate = Carbon::now()->addDays(7)->toDateString();
        $this->info("Scanning for investments expiring in 7 days (Target Maturity Date: {$targetMaturityDate})...");

        try {
            // Maturity Date SQL expression: reservation_date + duration_months
            $maturityDateSql = "DATE_ADD(investments.reservation_date, INTERVAL investment_products.duration_months MONTH)";

            // Fetch approved investments where computed maturity date equals today + 7 days
            $investments = Investment::with(['customer', 'investmentProduct'])
                ->join('investment_products', 'investments.investment_product_id', '=', 'investment_products.id')
                ->select('investments.*')
                ->where('investments.status', 'approved')
                ->whereRaw("DATE({$maturityDateSql}) = ?", [$targetMaturityDate])
                ->where(function ($q) {
                    $q->whereNull('renewal_sms_sent_at')
                      ->orWhere('renewal_sms_sent_at', '<', Carbon::now()->subDays(30));
                })
                ->get();

            if ($investments->isEmpty()) {
                $this->info("No investments found expiring in 7 days on {$targetMaturityDate}.");
                $this->logActivity('Info', 'RenewalSms', "No investments found expiring in 7 days for target date {$targetMaturityDate}", [
                    'target_date' => $targetMaturityDate
                ]);
                return 0;
            }

            $count = 0;
            foreach ($investments as $investment) {
                SendRenewalExpirySmsJob::dispatch($investment->id);
                $count++;
                $this->info("Dispatched pre-reminder SMS job for Investment #{$investment->id} (Policy: {$investment->policy_number})");

                $this->logActivity('Dispatch', 'RenewalSms', "Dispatched renewal pre-reminder SMS job for Policy #{$investment->policy_number}", [
                    'investment_id' => $investment->id,
                    'policy_number' => $investment->policy_number,
                    'customer_id' => $investment->customer_id
                ]);
            }

            $this->logActivity('Info', 'RenewalSms', "Completed dispatching {$count} renewal SMS job(s) for target date {$targetMaturityDate}", [
                'count' => $count,
                'target_date' => $targetMaturityDate
            ]);

            $this->info("Successfully dispatched {$count} renewal SMS job(s).");

            return 0;

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'RenewalSms', "Failed to execute renewal SMS command: " . $th->getMessage(), [
                'error' => $th->getMessage(),
                'target_date' => $targetMaturityDate
            ]);

            $this->error("Failed to execute renewal SMS command: " . $th->getMessage());
            return 1;
        }
    }
}
