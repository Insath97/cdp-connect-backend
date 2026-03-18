<?php

/**
 * Simple SMS Test Script
 * Run this from the root directory: php test-sms.php --number=7XXXXXXXX
 */

use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Get number from command line or use a placeholder
$options = getopt("", ["number:"]);
$number = $options['number'] ?? null;

if (!$number) {
    echo "Error: Please provide a mobile number using --number=7XXXXXXXX\n";
    echo "Usage: php test-sms.php --number=712345678\n";
    exit(1);
}

$message = "Test SMS from CDP Connect API at " . date('Y-m-d H:i:s');

echo "------------------------------------------\n";
echo "CDP Connect - SMS Test Utility\n";
echo "------------------------------------------\n";
echo "Target Number: $number\n";
echo "Message Body : $message\n";
echo "------------------------------------------\n";
echo "Initializing SMS Service...\n";

try {
    $smsService = resolve(SmsService::class);
    
    echo "Sending request to Dialog API...\n";
    $result = $smsService->sendSms($number, $message);

    if ($result) {
        echo "\nSUCCESS: SMS was sent successfully according to the API response.\n";
    } else {
        echo "\nFAILED: The SMS could not be sent.\n";
        echo "Check 'storage/logs/laravel.log' for detailed error logs and API responses.\n";
    }
} catch (\Throwable $th) {
    echo "\nCRITICAL ERROR: " . $th->getMessage() . "\n";
    Log::error("Manual SMS Test Failed: " . $th->getMessage());
}

echo "------------------------------------------\n";
