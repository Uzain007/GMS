<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberImportRequest;
use App\Http\Resources\MemberImportResource;
use App\Jobs\ProcessMemberImport;
use App\Models\MemberImport;
use App\Services\AuditService;
use App\Services\MemberImportPreviewService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
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
        MemberImportPreviewService $preview,
    ): JsonResponse {
        $file = $request->file('file');
        $disk = (string) config('filesystems.default');
        // Tenant-prefixed object keys prevent accidental cross-gym file reuse.
        $directory = "gyms/{$context->id()}/imports/members";
        $extension = mb_strtolower($file->getClientOriginalExtension()) ?: 'csv';
        $path = $file->storeAs($directory, Str::uuid().'.'.$extension, $disk);

        if (! $path) {
            return response()->json(['message' => 'The import file could not be stored.'], 500);
        }

        $import = null;
        try {
            $import = DB::transaction(function () use ($request, $file, $disk, $path): MemberImport {
                return MemberImport::query()->create([
                    'requested_by' => $request->user()->getKey(),
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'status' => ImportStatus::Previewed,
                ]);
            });
            $analysis = $preview->analyse($import);
            $import->update([
                'total_rows' => $analysis['summary']['total_rows'],
                'success_rows' => $analysis['summary']['valid_rows'],
                'failure_rows' => $analysis['summary']['invalid_rows'],
                'preview_summary' => $analysis['summary'],
                'errors' => $analysis['errors'] ?: null,
                'previewed_at' => now(),
            ]);
            $audit->record('member_import.previewed', $import, $request->user(), after: [
                'original_name' => $import->original_name,
                'preview_summary' => $analysis['summary'],
            ], request: $request);
        } catch (RuntimeException $exception) {
            $import?->delete();
            Storage::disk($disk)->delete($path);
            return response()->json(['message' => Str::limit($exception->getMessage(), 500)], 422);
        } catch (Throwable $exception) {
            $import?->delete();
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        return response()->json([
            'data' => (new MemberImportResource($import))->resolve($request),
        ], 201);
    }

    public function confirm(string $import, TenantContext $context, AuditService $audit): JsonResponse
    {
        $record = MemberImport::query()->findOrFail($import);
        abort_unless($record->status === ImportStatus::Previewed, 409, 'Only a previewed import can be confirmed.');
        if ((int) data_get($record->preview_summary, 'invalid_rows', 0) > 0) {
            return response()->json(['message' => 'Fix every validation problem and upload the file again before importing.'], 422);
        }

        DB::transaction(function () use ($record, $context, $audit): void {
            $record->update(['status' => ImportStatus::Queued, 'confirmed_at' => now()]);
            $audit->record('member_import.confirmed', $record, request()->user(), after: [
                'total_rows' => $record->total_rows,
                'status' => ImportStatus::Queued->value,
            ], request: request());
            // Redis receives only immutable IDs; the job re-establishes RLS context.
            ProcessMemberImport::dispatch($context->id(), $record->getKey())->afterCommit();
        });

        return response()->json(['data' => (new MemberImportResource($record->fresh()))->resolve(request())], 202);
    }

    public function show(string $import): MemberImportResource
    {
        return new MemberImportResource(MemberImport::query()->findOrFail($import));
    }
}
