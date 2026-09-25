<?php

namespace App\Services;

use App\Models\BankTransferReceipt;
use App\Models\Gym;
use App\Models\SaasSubscriptionPayment;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReceiptRetentionService
{
    public const RETENTION_MONTHS = 12;

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array{gyms:int,member_deleted:int,saas_deleted:int,failed:int} */
    public function runAll(): array
    {
        $totals = ['gyms' => 0, 'member_deleted' => 0, 'saas_deleted' => 0, 'failed' => 0];
        Gym::query()->orderBy('id')->each(function (Gym $gym) use (&$totals): void {
            $result = $this->runForGym($gym);
            $totals['gyms']++;
            foreach (['member_deleted', 'saas_deleted', 'failed'] as $key) {
                $totals[$key] += $result[$key];
            }
        });

        return $totals;
    }

    /** @return array{member_deleted:int,saas_deleted:int,failed:int} */
    public function runForGym(Gym $gym): array
    {
        // Rebind every query to one explicit tenant so application scope and
        // PostgreSQL RLS remain fail-closed during cross-tenant maintenance.
        return $this->tenant->run($gym, function (): array {
            $result = ['member_deleted' => 0, 'saas_deleted' => 0, 'failed' => 0];
            $cutoff = now()->subMonthsNoOverflow(self::RETENTION_MONTHS);

            BankTransferReceipt::query()
                ->whereNull('file_deleted_at')
                ->where('created_at', '<=', $cutoff)
                ->orderBy('id')
                ->chunkById(100, function ($receipts) use (&$result): void {
                    foreach ($receipts as $receipt) {
                        if ($this->deleteObject($receipt->storage_disk, $receipt->storage_path)) {
                            $receipt->forceFill(['file_deleted_at' => now()])->save();
                            $result['member_deleted']++;
                        } else {
                            $result['failed']++;
                        }
                    }
                });

            SaasSubscriptionPayment::query()
                ->whereNotNull('receipt_path')
                ->whereNull('receipt_deleted_at')
                ->where('created_at', '<=', $cutoff)
                ->orderBy('id')
                ->chunkById(100, function ($payments) use (&$result): void {
                    foreach ($payments as $payment) {
                        if ($this->deleteObject($payment->receipt_disk, $payment->receipt_path)) {
                            $payment->forceFill(['receipt_deleted_at' => now()])->save();
                            $result['saas_deleted']++;
                        } else {
                            $result['failed']++;
                        }
                    }
                });

            return $result;
        });
    }

    private function deleteObject(?string $diskName, ?string $path): bool
    {
        if (! filled($diskName) || ! filled($path)) {
            return false;
        }
        try {
            $disk = Storage::disk($diskName);
            if (! $disk->exists($path)) {
                return true;
            }

            return $disk->delete($path) && ! $disk->exists($path);
        } catch (Throwable) {
            return false;
        }
    }
}
