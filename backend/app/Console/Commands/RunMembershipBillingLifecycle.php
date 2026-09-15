<?php

namespace App\Console\Commands;

use App\Services\AutomatedMembershipBillingService;
use Illuminate\Console\Command;

class RunMembershipBillingLifecycle extends Command
{
    protected $signature = 'ironcore:membership-billing';
    protected $description = 'Generate membership invoices, reminders and overdue access restrictions';

    public function handle(AutomatedMembershipBillingService $billing): int
    {
        $result = $billing->runAll();
        $this->info(sprintf('Processed %d gyms; created %d invoices; queued %d reminders; restricted %d memberships.',
            $result['gyms'], $result['invoices_created'], $result['reminders_queued'], $result['restricted']));
        return self::SUCCESS;
    }
}
