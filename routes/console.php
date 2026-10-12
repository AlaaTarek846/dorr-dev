<?php

use App\Models\SecurityEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The account security log is kept for auth_flow.security_log_days (a year).
Schedule::command('model:prune', ['--model' => [SecurityEvent::class]])->daily()->withoutOverlapping();
