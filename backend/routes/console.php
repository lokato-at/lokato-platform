<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment('Keep shipping stable software.');
});

Schedule::call(function (): void {
    Artisan::call('children:daily-active-reset');
})
    ->name('children-daily-active-reset')
    ->timezone(env('APP_TIMEZONE', 'Europe/Vienna'))
    ->dailyAt('01:00');
