<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('subscriptions:end-grace')->hourly()->withoutOverlapping(55);

Schedule::command('events:send-weekly-agenda')->weeklyOn(6, '10:00')->timezone('Europe/Lisbon')->withoutOverlapping(60);

Schedule::command('prayer-cards:distribute')->hourly()->withoutOverlapping(55);

Schedule::command('prayer-cards:expire')->dailyAt('03:00')->timezone('Europe/Lisbon');
