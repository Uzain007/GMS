<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberImportRequest;
use App\Http\Resources\MemberImportResource;
use App\Jobs\ProcessMemberImport;
use App\Models\MemberImport;
use App\Services\AuditService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MemberImportController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return MemberImportResource::collection(
            MemberImport::query()->latest()->paginate(min(max((int) request('per_page', 25), 1), 100))
        );
    }

    public function store(
        StoreMemberImportRequest $request,
        TenantContext $context,
        AuditService $audit,
    ): JsonResponse {
        $file = $request->file('file');
        $disk = (string) config('filesystems.default');
        // Tenant-prefixed object keys prevent accidental cross-gym file reuse.
        $directory = "gyms/{$context->id()}/imports/members";
        $path = $file->storeAs($directory, Str::uuid().'.csv', $disk);

        if (! $path) {
            return response()->json(['message' => 'The import file could not be stored.'], 500);
        }

        try {
            $import = DB::transaction(function () use ($request, $audit, $file, $disk, $path, $context): MemberImport {
                $import = MemberImport::query()->create([
                    'requested_by' => $request->user()->getKey(),
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'status' => ImportStatus::Queued,
                ]);
                $audit->record('member_import.queued', $import, $request->user(), after: $import->toArray(), request: $request);

                // Redis receives only immutable IDs; the job re-establishes RLS context.
                ProcessMemberImport::dispatch($context->id(), $import->getKey())->afterCommit();
                return $import;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        return response()->json([
            'data' => (new MemberImportResource($import))->resolve($request),
        ], 202);
    }

    public function show(string $import): MemberImportResource
    {
        return new MemberImportResource(MemberImport::query()->findOrFail($import));
    }
}
