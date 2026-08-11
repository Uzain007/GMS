<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberExportStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberDataExportResource;
use App\Jobs\GenerateMemberDataExport;
use App\Models\Member;
use App\Models\MemberDataExport;
use App\Services\AuditService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberDataExportController extends Controller
{
    public function selfIndex(Request $request): AnonymousResourceCollection
    {
        return $this->index($this->linkedMember($request));
    }

    public function selfStore(Request $request, TenantContext $context, AuditService $audit): JsonResponse
    {
        return $this->store($request, $this->linkedMember($request), $context, $audit);
    }

    public function selfShow(Request $request, string $export): MemberDataExportResource
    {
        return $this->show($this->linkedMember($request), $export);
    }

    public function selfDownload(Request $request, string $export): StreamedResponse
    {
        return $this->download($this->linkedMember($request), $export);
    }

    public function index(Member $member): AnonymousResourceCollection
    {
        return MemberDataExportResource::collection(
            MemberDataExport::query()->where('member_id', $member->id)->latest()->paginate(25)
        );
    }

    public function store(Request $request, Member $member, TenantContext $context, AuditService $audit): JsonResponse
    {
        $export = DB::transaction(function () use ($request, $member, $context, $audit): MemberDataExport {
            $export = MemberDataExport::query()->create([
                'member_id' => $member->id,
                'requested_by' => $request->user()->getKey(),
                'status' => MemberExportStatus::Queued,
            ]);
            $audit->record('member_data_export.queued', $export, $request->user(), after: [
                'member_id' => $member->id,
                'status' => MemberExportStatus::Queued->value,
            ], request: $request);
            GenerateMemberDataExport::dispatch($context->id(), $export->id)->afterCommit();
            return $export;
        });

        return response()->json(['data' => (new MemberDataExportResource($export))->resolve($request)], 202);
    }

    public function show(Member $member, string $export): MemberDataExportResource
    {
        return new MemberDataExportResource($this->resolve($member, $export));
    }

    public function download(Member $member, string $export): StreamedResponse
    {
        $record = $this->resolve($member, $export);
        abort_unless($record->status === MemberExportStatus::Completed && $record->expires_at?->isFuture(), 404);

        return Storage::disk($record->storage_disk)->download(
            $record->storage_path,
            "ironcore-member-{$member->member_number}.json",
            ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store'],
        );
    }

    private function resolve(Member $member, string $export): MemberDataExport
    {
        return MemberDataExport::query()->where('member_id', $member->id)->findOrFail($export);
    }

    private function linkedMember(Request $request): Member
    {
        // Self-service never accepts a member UUID; the authenticated platform
        // identity must already be linked inside the resolved gym.
        return Member::query()->where('user_id', $request->user()->getKey())->firstOrFail();
    }
}
