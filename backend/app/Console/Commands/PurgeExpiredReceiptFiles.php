<?php

namespace App\Console\Commands;

use App\Services\ReceiptRetentionService;
use Illuminate\Console\Command;

class PurgeExpiredReceiptFiles extends Command
{
    protected $signature = 'ironcore:receipt-retention';

    protected $description = 'Delete expired private receipt objects while preserving financial records';

    public function handle(ReceiptRetentionService $retention): int
    {
        $result = $retention->runAll();
        $this->info(sprintf(
            'Processed %d gyms; deleted %d member receipt objects and %d SaaS receipt objects; %d failed.',
            $result['gyms'],
            $result['member_deleted'],
            $result['saas_deleted'],
            $result['failed'],
        ));

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
