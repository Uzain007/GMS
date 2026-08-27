<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Currency;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\ReviewBankTransferRequest;
use App\Http\Requests\StoreRefundRequest;
use App\Http\Resources\PaymentRefundResource;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $query = Payment::query()->with(['refunds', 'bankTransferReceipt'])->orderByDesc('created_at');
        foreach (['status', 'method', 'member_id', 'invoice_id'] as $filter) {
            if (request()->filled($filter)) {
                $query->where($filter, request($filter));
            }
        }
        if ($search = trim((string) request('search'))) {
            $query->where('receipt_number', 'like', mb_substr($search, 0, 50).'%');
        }
        return PaymentResource::collection($query->paginate($this->pageSize()));
    }

    public function store(StorePaymentRequest $request, PaymentService $service): JsonResponse
    {
        $result = $service->create($request->safe()->except('receipt'), $request->user(), $request, $request->file('receipt'));
        return response()->json([
            'data' => (new PaymentResource($result['payment']))->resolve($request),
            'meta' => [
                // The hosted URL belongs only to this checkout and is not a
                // reusable credential or stored card token.
                'checkout_url' => $result['checkout_url'],
                'idempotency_reused' => $result['reused'],
            ],
        ], $result['reused'] ? 200 : 201);
    }

    public function show(string $payment): PaymentResource
    {
        return new PaymentResource(Payment::query()->with(['refunds', 'bankTransferReceipt'])->findOrFail($payment));
    }

    public function refund(StoreRefundRequest $request, string $payment, PaymentService $service): PaymentRefundResource
    {
        $model = Payment::query()->findOrFail($payment);
        return new PaymentRefundResource($service->refund($model, $request->validated(), $request->user(), $request));
    }

    public function summary(PaymentService $service, TenantContext $context): JsonResponse
    {
        $requested = strtoupper(trim((string) request('currency', $context->gym()->base_currency->value)));
        $currency = Currency::tryFrom($requested) ?? $context->gym()->base_currency;
        return response()->json(['data' => $service->summary($currency->value)]);
    }

    public function reviewBankTransfer(
        ReviewBankTransferRequest $request,
        string $payment,
        PaymentService $service,
    ): PaymentResource {
        $model = Payment::query()->findOrFail($payment);
        return new PaymentResource($service->reviewBankTransfer(
            $model, $request->validated(), $request->user(), $request,
        ));
    }

    public function receipt(string $payment): StreamedResponse
    {
        $record = Payment::query()->with('bankTransferReceipt')->findOrFail($payment);
        abort_unless($record->bankTransferReceipt, 404);
        return $this->streamReceipt($record->bankTransferReceipt);
    }

    public static function streamReceipt(\App\Models\BankTransferReceipt $receipt): StreamedResponse
    {
        $disk = Storage::disk($receipt->storage_disk);
        abort_unless($disk->exists($receipt->storage_path), 404);
        $disposition = (new ResponseHeaderBag())->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $receipt->original_name,
            'bank-transfer-receipt',
        );

        return response()->stream(function () use ($disk, $receipt): void {
            $stream = $disk->readStream($receipt->storage_path);
            abort_unless(is_resource($stream), 404);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $receipt->mime_type,
            'Content-Length' => (string) $receipt->size_bytes,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    private function pageSize(): int
    {
        return min(max((int) request('per_page', 25), 1), 100);
    }
}
