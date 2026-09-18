<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
Schedule::command('kata:maintain-applications')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('queue:work protected_media_cleanup --stop-when-empty --max-time=60')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('panel:clean-tasks')->hourly()->withoutOverlapping();
