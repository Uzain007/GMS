<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(array $data, User $actor, Request $request): Invoice
    {
        return DB::transaction(function () use ($data, $actor, $request): Invoice {
            // Both lookups are constrained by Eloquent tenant scopes and RLS;
            // composite foreign keys retain the same proof at write time.
            $member = Member::query()->findOrFail($data['member_id']);
            $membership = isset($data['membership_id'])
                ? Membership::query()->findOrFail($data['membership_id'])
                : null;

            if ($membership && $membership->member_id !== $member->getKey()) {
                throw ValidationException::withMessages([
                    'membership_id' => ['The membership does not belong to the selected member.'],
                ]);
            }

            $subtotal = 0;
            $tax = 0;
            $items = [];
            foreach ($data['items'] as $item) {
                $lineSubtotal = (int) $item['quantity'] * (int) $item['unit_amount_minor'];
                $lineTax = (int) ($item['tax_amount_minor'] ?? 0);
                $lineTotal = $lineSubtotal + $lineTax;
                if ($lineTotal > 1000000000000000) {
                    throw ValidationException::withMessages(['items' => ['An invoice line exceeds the supported amount.']]);
                }
                $subtotal += $lineSubtotal;
                $tax += $lineTax;
                $items[] = [
                    'description' => $item['description'],
                    'quantity' => (int) $item['quantity'],
                    'unit_amount_minor' => (int) $item['unit_amount_minor'],
                    'subtotal_amount_minor' => $lineSubtotal,
                    'tax_amount_minor' => $lineTax,
                    'total_amount_minor' => $lineTotal,
                    'metadata' => $item['metadata'] ?? null,
                ];
            }

            $total = $subtotal + $tax;
            if ($total <= 0 || $total > 1000000000000000) {
                throw ValidationException::withMessages(['items' => ['The invoice total must be greater than zero and within the supported amount.']]);
            }

            $invoice = Invoice::query()->create([
                'member_id' => $member->getKey(),
                'membership_id' => $membership?->getKey(),
                'branch_id' => $data['branch_id'] ?? $membership?->branch_id ?? $member->home_branch_id,
                'created_by' => $actor->getKey(),
                'number' => 'INV-'.Str::upper((string) Str::ulid()),
                'status' => InvoiceStatus::Open,
                'currency' => $data['currency'],
                'subtotal_amount_minor' => $subtotal,
                'tax_amount_minor' => $tax,
                'total_amount_minor' => $total,
                'paid_amount_minor' => 0,
                'due_amount_minor' => $total,
                'issued_at' => $data['issued_at'] ?? now(),
                'due_at' => $data['due_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            // The relationship still applies the invoice item's independent
            // tenant scope and automatically receives gym_id on every insert.
            $invoice->items()->createMany($items);
            $invoice->load('items');
            $this->audit->record('invoice.created', $invoice, $actor, after: $invoice->toArray(), request: $request);

            return $invoice;
        });
    }
}
