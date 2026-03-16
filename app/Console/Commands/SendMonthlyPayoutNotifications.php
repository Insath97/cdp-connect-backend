<?php

namespace App\Console\Commands;

use App\Models\Investment;
use App\Mail\MonthlyPayoutMail;
use App\Services\SmsService;
use App\Traits\InvestmentCalculationTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendMonthlyPayoutNotifications extends Command
{
    use InvestmentCalculationTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-monthly-payout-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send monthly payout notifications (Email & SMS) to investors';

    /**
     * Execute the console command.
     */
    public function handle(SmsService $smsService)
    {
        $today = now();
        $this->info("Scanning for payouts for today: " . $today->format('Y-m-d'));

        // 1. Fetch approved investments that have a monthly payment date today
        $investments = Investment::with(['customer.user', 'investmentProduct'])
            ->where('status', 'approved')
            ->whereDay('monthly_payment_date', $today->day)
            ->get();

        if ($investments->isEmpty()) {
            $this->info("No payouts scheduled for today.");
            return;
        }

        foreach ($investments as $investment) {
            try {
                // 2. Calculate payout amount
                $calculations = $this->calculateInvestmentROI(
                    (float)$investment->investment_amount,
                    $investment->investmentProduct
                );

                $payoutAmount = $calculations['monthly_return'];
                $monthYear = $today->format('F Y');

                $customer = $investment->customer;
                $recipientEmail = $customer->user->email ?? null;
                $recipientPhone = $customer->phone_primary ?? null;

                $data = [
                    'customer_name' => $customer->full_name,
                    'payout_amount' => $payoutAmount,
                    'month_year' => $monthYear,
                    'policy_number' => $investment->policy_number,
                    'product_name' => $investment->investmentProduct->name,
                    'investment_amount' => $investment->investment_amount,
                ];

                // 3. Send Email
                if ($recipientEmail) {
                    Mail::to($recipientEmail)->send(new MonthlyPayoutMail($data));
                    $this->info("Email sent to: {$recipientEmail}");
                } else {
                    $this->warn("No email found for customer: {$customer->full_name}");
                }

                // 4. Send SMS
                if ($recipientPhone) {
                    $smsMessage = "Dear {$customer->full_name}, your monthly return of LKR " . number_format($payoutAmount, 2) . " for {$monthYear} has been processed. CDP Connect.";
                    $smsService->sendSms($recipientPhone, $smsMessage);
                    $this->info("SMS sent to: {$recipientPhone}");
                } else {
                    $this->warn("No phone found for customer: {$customer->full_name}");
                }

                Log::info("Monthly payout notification sent", [
                    'investment_id' => $investment->id,
                    'amount' => $payoutAmount
                ]);

            } catch (\Throwable $th) {
                Log::error("Failed to process payout notification for investment {$investment->id}: " . $th->getMessage());
                $this->error("Error processing investment {$investment->id}");
            }
        }

        $this->info("All processed.");
    }
}
