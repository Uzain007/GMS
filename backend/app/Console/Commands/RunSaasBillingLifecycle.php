<?php

namespace App\Console\Commands;

use App\Services\AutomatedSaasBillingService;
use Illuminate\Console\Command;

class RunSaasBillingLifecycle extends Command
{
    protected $signature = 'ironcore:saas-billing';
    protected $description = 'Process SaaS trials, invoices, reminders and billing restrictions';

    public function handle(AutomatedSaasBillingService $billing): int
    {
        $result = $billing->runAll();
        $this->info(sprintf(
            'Processed %d gyms; created %d invoices; queued %d reminders; restricted %d subscriptions; %d tenants failed.',
            $result['gyms'], $result['invoices_created'], $result['reminders_queued'], $result['restricted'], $result['failed'],
        ));

        // Every remaining tenant has already been processed. A non-zero exit
        // keeps partial failures visible to scheduler monitoring and alerting.
        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
