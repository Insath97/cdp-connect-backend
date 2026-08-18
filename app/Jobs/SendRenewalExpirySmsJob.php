<?php

namespace App\Jobs;

use App\Models\Investment;
use App\Services\SmsService;
use App\Traits\ActivityLogTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendRenewalExpirySmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ActivityLogTrait;

    /**
     * Number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 60;

    protected $investmentId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $investmentId)
    {
        $this->investmentId = $investmentId;
    }

    /**
     * Execute the job.
     */
    public function handle(SmsService $smsService): void
    {
        try {
            $investment = Investment::with(['customer', 'investmentProduct'])->find($this->investmentId);

            if (!$investment || $investment->status !== 'approved') {
                $this->logActivity('Warning', 'RenewalSms', "SendRenewalExpirySmsJob: Investment #{$this->investmentId} not found or not approved.", [
                    'investment_id' => $this->investmentId
                ]);
                return;
            }

            $customer = $investment->customer;
            $recipientPhone = $customer->phone_primary ?? null;

            if (!$recipientPhone) {
                $this->logActivity('Warning', 'RenewalSms', "SendRenewalExpirySmsJob: Customer for investment #{$investment->id} has no primary phone number.", [
                    'investment_id' => $investment->id,
                    'customer_id' => $customer->id ?? null
                ]);
                return;
            }

            $customerName = $customer->full_name ?? 'Valued Client';
            $productName = $investment->investmentProduct->name ?? 'Investment Plan';
            $invAmount = number_format((float) $investment->investment_amount, 2);

            $reservationDate = $investment->reservation_date ? Carbon::parse($investment->reservation_date) : Carbon::now();
            $durationMonths = (int) ($investment->investmentProduct->duration_months ?? 0);
            $maturityDate = $reservationDate->copy()->addMonths($durationMonths)->format('Y-m-d');

            $smsMessage = "Dear {$customerName},\n\n" .
                "RENEWAL REMINDER: Your Investment Policy [{$investment->policy_number}] ({$productName}) of LKR {$invAmount} will mature in 7 days on {$maturityDate}.\n\n" .
                "Please contact your agent or visit our branch to renew your investment.\n\n" .
                "Thank you for choosing CDP Empire (Pvt) Ltd.\n" .
                "Hotline: +94 114 007 007\n" .
                "Website: https://cdp.lk/";

            $sent = $smsService->sendSms($recipientPhone, $smsMessage);

            if ($sent) {
                $investment->update([
                    'renewal_sms_sent_at' => now()
                ]);

                $this->logActivity('Success', 'RenewalSms', "Renewal pre-reminder SMS sent successfully to {$recipientPhone} for Policy #{$investment->policy_number}", [
                    'investment_id' => $investment->id,
                    'policy_number' => $investment->policy_number,
                    'recipient_phone' => $recipientPhone,
                    'maturity_date' => $maturityDate,
                    'amount' => $investment->investment_amount
                ]);
            } else {
                $this->logActivity('Error', 'RenewalSms', "Failed to send renewal SMS to {$recipientPhone} for Policy #{$investment->policy_number}", [
                    'investment_id' => $investment->id,
                    'policy_number' => $investment->policy_number,
                    'recipient_phone' => $recipientPhone
                ]);

                throw new \Exception("Dialog SMS Gateway returned failure response.");
            }

        } catch (\Throwable $th) {
            $this->logActivity('Error', 'RenewalSms', "SendRenewalExpirySmsJob Exception for investment #{$this->investmentId}: " . $th->getMessage(), [
                'investment_id' => $this->investmentId,
                'error' => $th->getMessage()
            ]);

            throw $th; // Re-throw to trigger job retries if configured
        }
    }
}
