<?php

namespace App\Console\Commands;

use App\Support\ProductionConfigurationPreflight;
use Illuminate\Console\Command;

final class ProductionPreflightCommand extends Command
{
    protected $signature = 'ironcore:production-preflight';

    protected $description = 'Fail closed when resolved production configuration is unsafe or incomplete';

    public function handle(ProductionConfigurationPreflight $preflight): int
    {
        $failures = $preflight->failures();

        if ($failures === []) {
            $this->info('IronCore production configuration preflight passed.');

            return self::SUCCESS;
        }

        $this->error('IronCore production configuration preflight failed:');
        foreach ($failures as $failure) {
            $this->line(' - '.$failure);
        }

        return self::FAILURE;
    }
}
