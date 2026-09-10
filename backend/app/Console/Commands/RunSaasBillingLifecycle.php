<?php

namespace App\Console\Commands;

use App\Services\AutomatedSaasBillingService;
use Illuminate\Console\Command;

class RunSaasBillingLifecycle extends Command
{
    protected $signature = 'ironcore:saas-billing';
    protected $description = 'Generate due SaaS invoices and apply reminder/grace-period lifecycle rules';

    public function handle(AutomatedSaasBillingService $billing): int
    {
        $result = $billing->runAll();
        $this->info(sprintf(
            'Processed %d gyms; created %d invoices; queued %d reminders; restricted %d subscriptions.',
            $result['gyms'], $result['invoices_created'], $result['reminders_queued'], $result['restricted'],
        ));
        return self::SUCCESS;
    }
}
