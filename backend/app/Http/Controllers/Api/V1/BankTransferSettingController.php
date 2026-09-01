<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBankTransferSettingRequest;
use App\Http\Resources\GymBankTransferSettingResource;
use App\Models\GymBankTransferSetting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BankTransferSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $setting = GymBankTransferSetting::query()->first();

        return response()->json(['data' => $setting
            ? (new GymBankTransferSettingResource($setting))->resolve(request())
            : null]);
    }

    public function update(UpdateBankTransferSettingRequest $request, AuditService $audit): GymBankTransferSettingResource
    {
        $data = $request->safe()->except('reason');
        $setting = DB::transaction(function () use ($data, $request, $audit): GymBankTransferSetting {
            $setting = GymBankTransferSetting::query()->first();
            $before = $setting ? $this->auditShape($setting) : [];
            $setting ??= new GymBankTransferSetting();
            $setting->fill($data);
            $setting->updated_by = $request->user()->getKey();
            $setting->save();
            $fresh = $setting->fresh();

            // Audit records configuration state, never bank identifiers.
            $audit->record(
                'gym.bank_transfer_settings.updated', $fresh, $request->user(),
                $before, $this->auditShape($fresh), (string) $request->string('reason'), $request,
            );
            return $fresh;
        });

        return new GymBankTransferSettingResource($setting);
    }

    /** @return array<string, bool> */
    private function auditShape(GymBankTransferSetting $setting): array
    {
        return [
            'enabled' => $setting->enabled,
            'account_name_configured' => filled($setting->account_name),
            'bank_name_configured' => filled($setting->bank_name),
            'account_identifier_configured' => filled($setting->account_number_or_iban),
            'routing_details_configured' => filled($setting->routing_details),
            'payment_instructions_configured' => filled($setting->payment_instructions),
        ];
    }
}
