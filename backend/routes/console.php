<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('ironcore:status', function (): void {
    $this->info('IronCore API is ready.');
})->purpose('Check that the IronCore command layer is available');

// One scheduler process runs this hourly. Each tenant is rebound separately by
// the command service, so forced PostgreSQL RLS remains active throughout.
// Hourly processing keeps exact trial expiries bounded without creating a
// second billing scheduler; durable idempotency prevents duplicate notices.
Schedule::command('ironcore:saas-billing')->hourlyAt(5)->withoutOverlapping();
Schedule::command('ironcore:membership-billing')->dailyAt('01:15')->withoutOverlapping();
// Delete only private receipt objects after their 12-month retention period;
// the command leaves every financial and audit record intact.
Schedule::command('ironcore:receipt-retention')->dailyAt('02:00')->withoutOverlapping();
