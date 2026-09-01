<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Gym;
use App\Models\Member;
use App\Services\AuditService;
use App\Services\MemberImportPreviewService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Shuchkin\SimpleXLSXGen;

class MemberRosterController extends Controller
{
    public function template(Request $request)
    {
        $format = mb_strtolower((string) $request->query('format', 'csv'));
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, 'Template format must be csv or xlsx.');
        $rows = [
            MemberImportPreviewService::HEADERS,
            ['Amina', 'Khan', 'amina.khan@example.test', '+92 300 1234567', '', '', '', 'lead', '1994-05-14', now()->toDateString()],
        ];
        if ($format === 'xlsx') {
            $bytes = (string) SimpleXLSXGen::fromArray($rows)->setDefaultFont('Arial')->setDefaultFontSize(11);
            return response($bytes, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="ironcore-member-import-template.xlsx"',
                'Cache-Control' => 'private, no-store',
            ]);
        }
        return $this->csvResponse('ironcore-member-import-template.csv', function ($stream) use ($rows): void {
            foreach ($rows as $row) fputcsv($stream, $row);
        });
    }

    public function export(Request $request, AuditService $audit): StreamedResponse
    {
        $context = app(TenantContext::class);
        $gym = $context->gym();
        $audit->record('member_roster.exported', $gym, $request->user(), after: [
            'format' => 'csv', 'scope' => 'selected_gym',
        ], request: $request);
        return $this->csvResponse('ironcore-members-'.now()->format('Ymd-His').'.csv', function ($stream) use ($context, $gym): void {
            // Stream callbacks execute after route middleware unwinds, so the
            // verified gym must be re-bound explicitly for ORM scope and RLS.
            $context->run($gym, function () use ($stream): void {
                fputcsv($stream, [...MemberImportPreviewService::HEADERS, 'member_code']);
                Member::query()->with(['homeBranch', 'memberships' => fn ($query) => $query->with('plan')->latest('starts_at')])
                    ->orderBy('id')->chunkById(500, function ($members) use ($stream): void {
                        foreach ($members as $member) {
                            $membership = $member->memberships->first();
                            fputcsv($stream, [
                                $member->first_name, $member->last_name, $member->email, $member->phone,
                                $member->member_number, $member->homeBranch?->code, $membership?->plan?->code,
                                $member->status->value, $member->date_of_birth?->toDateString(),
                                $member->joined_at?->toDateString(), $member->member_code,
                            ]);
                        }
                    });
            });
        });
    }

    public function platformExport(Request $request, TenantContext $context, AuditService $audit): StreamedResponse
    {
        $audit->record('platform.member_roster.exported', $request->user(), $request->user(), after: [
            'format' => 'csv', 'scope' => 'all_gyms',
        ], request: $request);
        return $this->csvResponse('ironcore-all-gyms-members-'.now()->format('Ymd-His').'.csv', function ($stream) use ($context): void {
            fputcsv($stream, ['gym_id', 'gym_name', ...MemberImportPreviewService::HEADERS, 'member_code']);
            Gym::query()->orderBy('id')->each(function (Gym $gym) use ($stream, $context): void {
                // Platform export enters each gym explicitly; ordinary member
                // scopes and PostgreSQL RLS are never disabled for convenience.
                $context->run($gym, function () use ($stream, $gym): void {
                    Member::query()->with(['homeBranch', 'memberships' => fn ($query) => $query->with('plan')->latest('starts_at')])
                        ->orderBy('id')->chunkById(500, function ($members) use ($stream, $gym): void {
                            foreach ($members as $member) {
                                $membership = $member->memberships->first();
                                fputcsv($stream, [
                                    $gym->id, $gym->name, $member->first_name, $member->last_name,
                                    $member->email, $member->phone, $member->member_number,
                                    $member->homeBranch?->code, $membership?->plan?->code, $member->status->value,
                                    $member->date_of_birth?->toDateString(), $member->joined_at?->toDateString(),
                                    $member->member_code,
                                ]);
                            }
                        });
                });
            });
        });
    }

    private function csvResponse(string $filename, callable $write): StreamedResponse
    {
        return response()->streamDownload(function () use ($write): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            $write($stream);
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
