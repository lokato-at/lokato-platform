<?php

use Illuminate\Support\Facades\Schedule;

// Cached scheduler definition (uses Schedule::command instead of Schedule::call
// with a Closure so that `schedule:cache` can serialize it).
Schedule::command('children:daily-active-reset')
    ->dailyAt('01:00')
    ->timezone(config('app.timezone', 'Europe/Vienna'))
    ->name('children-daily-active-reset');
