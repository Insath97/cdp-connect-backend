<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/* Schedule::command('app:send-monthly-payout-notifications')->dailyAt('00:00'); */
Schedule::command('targets:bulk-setup')->monthlyOn(1, '00:00');

// Daily 08:00 AM 7-Day Renewal Pre-Reminder SMS Notification
Schedule::command('app:send-renewal-expiry-sms')->dailyAt('08:00');


