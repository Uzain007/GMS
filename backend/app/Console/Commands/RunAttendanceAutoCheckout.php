<?php

namespace App\Console\Commands;

use App\Services\AutomatedAttendanceCheckoutService;
use Illuminate\Console\Command;

class RunAttendanceAutoCheckout extends Command
{
    protected $signature = 'ironcore:attendance-auto-checkout';
    protected $description = 'Close member and employee attendance sessions that exceeded their allowed duration';

    public function handle(AutomatedAttendanceCheckoutService $service): int
    {
        $result = $service->runAll();
        $this->info(sprintf('Processed %d gyms; automatically checked out %d members and %d employees.', $result['gyms'], $result['members'], $result['staff']));
        return self::SUCCESS;
    }
}
