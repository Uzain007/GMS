<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('ironcore:status', function (): void {
    $this->info('IronCore API is ready.');
})->purpose('Check that the IronCore command layer is available');

// One scheduler process runs this daily. Each tenant is rebound separately by
// the command service, so forced PostgreSQL RLS remains active throughout.
Schedule::command('ironcore:saas-billing')->dailyAt('01:00')->withoutOverlapping();
