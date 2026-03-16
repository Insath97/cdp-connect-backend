<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected $url;
    protected $username;
    protected $password;
    protected $mask;

    public function __construct()
    {
        $this->url = env('DIALOG_SMS_URL', 'https://esms.dialog.lk/api/v1/send');
        $this->username = env('DIALOG_SMS_USERNAME');
        $this->password = env('DIALOG_SMS_PASSWORD');
        $this->mask = env('DIALOG_SMS_MASK', 'CDP EMPIRE');
    }

    /**
     * Send SMS via Dialog Gateway
     * 
     * @param string $number (Format: 947XXXXXXXX)
     * @param string $message
     * @return bool
     */
    public function sendSms(string $number, string $message): bool
    {
        try {
            // Ensure number is in correct format (94 prefix)
            $number = $this->formatNumber($number);

            $response = Http::post($this->url, [
                'username' => $this->username,
                'password' => $this->password,
                'msisdn' => $number,
                'message' => $message,
                'sourceAddress' => $this->mask,
            ]);

            if ($response->successful()) {
                Log::info('SMS sent successfully', ['number' => $number]);
                return true;
            }

            Log::error('SMS sending failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'number' => $number
            ]);
            
            return false;

        } catch (\Throwable $th) {
            Log::error('SMS Service Error: ' . $th->getMessage());
            return false;
        }
    }

    /**
     * Format phone number to 94XXXXXXXX style
     */
    protected function formatNumber(string $number): string
    {
        $number = preg_replace('/[^0-9]/', '', $number);
        
        if (str_starts_with($number, '0')) {
            $number = '94' . substr($number, 1);
        } elseif (!str_starts_with($number, '94')) {
            $number = '94' . $number;
        }

        return $number;
    }
}
