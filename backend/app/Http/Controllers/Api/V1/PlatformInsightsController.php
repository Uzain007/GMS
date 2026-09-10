<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\PlatformInsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Shuchkin\SimpleXLSXGen;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformInsightsController extends Controller
{
    public function billing(Request $request, PlatformInsightsService $insights): JsonResponse
    {
        $filters = $request->validate([
            'gym_id' => ['nullable', 'uuid', 'exists:gyms,id'], 'plan_id' => ['nullable', 'uuid', 'exists:saas_plans,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'upcoming', 'due', 'past_due', 'open', 'paid', 'void', 'cancelled', 'uncollectible'])],
            'currency' => ['nullable', Rule::in(['GBP', 'USD', 'PKR', 'AED', 'SAR'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $result = $insights->billing($filters);
        $result['invoices'] = $this->page($result['invoices'], $request);
        return response()->json(['data' => $result]);
    }

    public function members(Request $request, PlatformInsightsService $insights): JsonResponse
    {
        $filters = $this->memberFilters($request);
        $pageNumber = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $result = $insights->members($filters, ($pageNumber - 1) * $perPage, $perPage);
        $paginator = new LengthAwarePaginator($result['rows'], $result['total'], $perPage, $pageNumber);
        $page = [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
            ],
        ];
        $page['meta']['facets'] = $insights->memberFacets($filters['gym_id'] ?? null);
        return response()->json($page);
    }

    public function exportMembers(Request $request, PlatformInsightsService $insights, AuditService $audit): Response|StreamedResponse
    {
        $filters = $this->memberFilters($request);
        $format = mb_strtolower((string) $request->query('format', 'csv'));
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, 'Export format must be csv or xlsx.');
        $selected = array_values(array_filter((array) $request->input('member_ids', []), 'is_string'));
        $rows = $insights->members($filters)['rows'];
        if ($selected !== []) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => in_array($row['id'], $selected, true)));
        }
        $sheet = [['Gym', 'Member', 'Email', 'Phone', 'Member Code', 'Profile status', 'Membership status', 'Plan', 'Branch', 'Joined']];
        foreach ($rows as $row) {
            $sheet[] = [$row['gym_name'], $row['name'], $row['email'], $row['phone'], $row['member_code'], $row['status'], $row['membership_status'], $row['plan']['name'] ?? null, $row['branch']['name'] ?? null, $row['joined_at']];
        }
        $audit->record('platform.member_directory.exported', $request->user(), $request->user(), after: [
            'format' => $format, 'row_count' => count($rows), 'selection' => $selected === [] ? 'filtered' : 'selected',
        ], request: $request);
        $name = 'ironcore-global-members-'.now()->format('Ymd-His');
        if ($format === 'xlsx') {
            return response((string) SimpleXLSXGen::fromArray($sheet), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$name}.xlsx\"", 'Cache-Control' => 'private, no-store',
            ]);
        }
        return response()->streamDownload(function () use ($sheet): void {
            $stream = fopen('php://output', 'wb'); fwrite($stream, "\xEF\xBB\xBF");
            foreach ($sheet as $row) fputcsv($stream, $row); fclose($stream);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function analytics(Request $request, PlatformInsightsService $insights): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        return response()->json(['data' => $insights->analytics($filters)]);
    }

    /** @return array<string,mixed> */
    private function memberFilters(Request $request): array
    {
        return $request->validate([
            'gym_id' => ['nullable', 'uuid', 'exists:gyms,id'], 'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'], 'membership_status' => ['nullable', 'string', 'max:30'],
            'plan_id' => ['nullable', 'uuid'], 'branch_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'member_ids' => ['nullable', 'array', 'max:5000'], 'member_ids.*' => ['uuid'],
        ]);
    }

    /** @param list<array<string,mixed>> $rows */
    private function page(array $rows, Request $request): array
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $paginator = new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page);
        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
