<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('ironcore:status', function (): void {
    $this->info('IronCore API is ready.');
})->purpose('Check that the IronCore command layer is available');
