<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MpesaWebhookController extends Controller
{
    public function store(Request $request, string $callbackToken): JsonResponse
    {
        $configuredToken = trim((string) config('kdpay.mpesa.callback_token'));
        if ($configuredToken === '' || !hash_equals($configuredToken, $callbackToken)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $validated = $request->validate([
            'input_OriginalConversationID' => ['nullable', 'string', 'max:100', 'required_without:input_ThirdPartyConversationID'],
            'input_ThirdPartyConversationID' => ['nullable', 'string', 'max:100', 'required_without:input_OriginalConversationID'],
            'input_TransactionID' => ['nullable', 'string', 'max:100'],
            'input_ResultCode' => ['required'],
        ]);

        $conversationId = $validated['input_OriginalConversationID'] ?? null;
        $thirdPartyReference = $validated['input_ThirdPartyConversationID'] ?? null;
        $resultCode = $validated['input_ResultCode'];

        if (!is_string($resultCode) && !is_int($resultCode)) {
            return response()->json(['message' => 'The callback result code is invalid.'], 422);
        }

        $resultCode = (string) $resultCode;

        DB::transaction(function () use ($conversationId, $thirdPartyReference, $resultCode): void {
            $transaction = PaymentTransaction::query()
                ->where('provider', 'mpesa')
                ->where(function ($query) use ($conversationId, $thirdPartyReference): void {
                    if (is_string($thirdPartyReference)) {
                        $query->where('idempotency_key', $thirdPartyReference);
                    }

                    if (is_string($conversationId)) {
                        $method = is_string($thirdPartyReference) ? 'orWhere' : 'where';
                        $query->{$method}('provider_reference', $conversationId);
                    }
                })
                ->lockForUpdate()
                ->first();

            if ($transaction === null || $transaction->status !== 'initiated') {
                return;
            }

            $attributes = [
                'status' => $resultCode === '0' ? 'completed' : 'failed',
            ];

            if ($transaction->provider_reference === null && is_string($conversationId)) {
                $attributes['provider_reference'] = $conversationId;
            }

            $transaction->update($attributes);
        });

        return response()->json([
            'output_OriginalConversationID' => $conversationId,
            'output_ResponseCode' => '0',
            'output_ResponseDesc' => 'Successfully Accepted Result',
            'output_ThirdPartyConversationID' => $thirdPartyReference,
        ]);
    }
}
