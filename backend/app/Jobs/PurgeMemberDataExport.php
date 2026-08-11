<?php

namespace App\Jobs;

use App\Enums\MemberExportStatus;
use App\Models\Gym;
use App\Models\MemberDataExport;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class PurgeMemberDataExport implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $gymId,
        public readonly string $exportId,
    ) {}

    public function handle(TenantContext $context): void
    {
        $gym = Gym::query()->find($this->gymId);
        if (! $gym) {
            return;
        }

        $context->run($gym, function (): void {
            $export = MemberDataExport::query()->find($this->exportId);
            if (! $export || ! $export->expires_at?->isPast()) {
                return;
            }

            if ($export->storage_disk && $export->storage_path) {
                Storage::disk($export->storage_disk)->delete($export->storage_path);
            }

            // Retain only auditable request metadata after deleting export bytes.
            $export->update([
                'status' => MemberExportStatus::Expired,
                'storage_disk' => null,
                'storage_path' => null,
            ]);
        });
    }
}
