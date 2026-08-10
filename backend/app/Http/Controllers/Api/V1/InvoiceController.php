<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvoiceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $query = Invoice::query()->with('items')->orderByDesc('created_at');
        if (request()->filled('status')) {
            $query->where('status', request('status'));
        }
        if (request()->filled('member_id')) {
            $query->where('member_id', request('member_id'));
        }
        if ($search = trim((string) request('search'))) {
            // Prefix receipt searches use the tenant-leading unique index.
            $query->where('number', 'like', mb_substr($search, 0, 50).'%');
        }
        return InvoiceResource::collection($query->paginate($this->pageSize()));
    }

    public function store(StoreInvoiceRequest $request, InvoiceService $service): InvoiceResource
    {
        return new InvoiceResource($service->create($request->validated(), $request->user(), $request));
    }

    public function show(string $invoice): InvoiceResource
    {
        return new InvoiceResource(Invoice::query()->with('items')->findOrFail($invoice));
    }

    private function pageSize(): int
    {
        return min(max((int) request('per_page', 25), 1), 100);
    }
}
