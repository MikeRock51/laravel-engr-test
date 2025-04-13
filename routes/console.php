<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\{Artisan, Schedule};

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('claims:process-daily-batch')
    ->dailyAt('00:00')
    ->appendOutputTo(storage_path('logs/daily-claim-batches.log'));
