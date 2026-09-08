<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\SimplePdfDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Shuchkin\SimpleXLSXGen;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Tenancy\TenantContext;

class AuditLogController extends Controller
{
    public function platform(AuditLogIndexRequest $request): AnonymousResourceCollection|Response|StreamedResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return $this->respond($request, $this->query($request));
    }

    public function tenant(AuditLogIndexRequest $request, TenantContext $context): AnonymousResourceCollection|Response|StreamedResponse
    {
        return $this->respond($request, $this->query($request, $context->id()));
    }

    private function query(AuditLogIndexRequest $request, ?string $tenantId = null): Builder
    {
        $data = $request->validated();
        $query = AuditLog::query()->with(['actor:id,name,email', 'gym:id,name'])
            ->where('created_at', '>=', $data['from'] ?? now()->subDays(365)->startOfDay())
            ->when($tenantId, fn (Builder $query, string $id) => $query->where('gym_id', $id))
            ->when($data['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->when(! $tenantId && ($data['gym_id'] ?? null), fn (Builder $query) => $query->where('gym_id', $data['gym_id']))
            ->when($data['actor_id'] ?? null, fn (Builder $query, string $id) => $query->where('actor_id', $id))
            ->when($data['role'] ?? null, fn (Builder $query, string $role) => $query->where('actor_role', $role))
            ->when($data['action'] ?? null, fn (Builder $query, string $action) => $query->where('event', 'like', "%{$action}%"))
            ->when($data['resource'] ?? null, fn (Builder $query, string $resource) => $query->where('auditable_type', 'like', "%{$resource}%"))
            ->when($data['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('event', 'like', "%{$search}%")
                        ->orWhere('reason', 'like', "%{$search}%")
                        ->orWhere('auditable_type', 'like', "%{$search}%")
                        ->orWhereHas('actor', fn (Builder $actor) => $actor->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('gym', fn (Builder $gym) => $gym->where('name', 'like', "%{$search}%"));
                });
            });

        return $query->latest('created_at')->latest('id');
    }

    private function respond(AuditLogIndexRequest $request, Builder $query): AnonymousResourceCollection|Response|StreamedResponse
    {
        $format = $request->validated('format');
        if (! $format) {
            return AuditLogResource::collection($query->paginate((int) ($request->validated('per_page') ?? 25)));
        }

        $rows = [['Date/time', 'User', 'Email', 'Role', 'Gym', 'Action', 'Resource', 'Resource ID', 'Old value', 'New value', 'Audit reason', 'IP address', 'Device']];
        $query->reorder()->chunkById(500, function ($logs) use (&$rows): void {
            foreach ($logs as $log) {
                $rows[] = [
                    $log->created_at?->toIso8601String(), $log->actor?->name, $log->actor?->email,
                    $log->actor_role, $log->gym?->name, $log->event, $log->auditable_type,
                    $log->auditable_id, json_encode($log->before_values), json_encode($log->after_values),
                    $log->reason, $log->ip_address, $log->user_agent,
                ];
            }
        }, 'id');

        $name = 'ironcore-audit-log-'.now()->format('Ymd-His');
        if ($format === 'xlsx') {
            return response((string) SimpleXLSXGen::fromArray($rows), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$name}.xlsx\"",
                'Cache-Control' => 'private, no-store',
            ]);
        }
        if ($format === 'pdf') {
            $lines = array_map(fn (array $row): string => implode(' | ', array_slice($row, 0, 7)), array_slice($rows, 1));
            return response(SimplePdfDocument::fromLines($lines, 'IronCore Audit History'), 200, [
                'Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}.pdf\"", 'Cache-Control' => 'private, no-store',
            ]);
        }

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($stream, $row);
            }
            fclose($stream);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
