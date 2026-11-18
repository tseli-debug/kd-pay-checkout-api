<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MpesaWebhookController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $conversationId = $request->input('input_OriginalConversationID');
        $thirdPartyReference = $request->input('input_ThirdPartyConversationID');
        $providerReference = $request->input('input_TransactionID');
        $resultCode = $request->input('input_ResultCode');

        $transaction = PaymentTransaction::query()
            ->when(is_string($thirdPartyReference), fn ($query) => $query->where('idempotency_key', $thirdPartyReference))
            ->when(is_string($conversationId), fn ($query) => $query->where('provider_reference', $conversationId))
            ->first();

        if ($transaction !== null) {
            $transaction->forceFill([
                'provider_reference' => $providerReference ?: $conversationId ?: $transaction->provider_reference,
                'status' => $resultCode === 'INS-0' ? 'completed' : 'failed',
            ])->save();
        }

        return response()->json([
            'output_OriginalConversationID' => $conversationId,
            'output_ResponseCode' => '0',
            'output_ResponseDesc' => 'Successfully Accepted Result',
            'output_ThirdPartyConversationID' => $thirdPartyReference,
        ]);
    }
}
