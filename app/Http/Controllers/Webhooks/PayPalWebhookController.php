<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Services\PayPalGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayPalWebhookController extends Controller
{
    public function __construct(private readonly PayPalGateway $paypal)
    {
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->paypal->verifyWebhook($request)) {
            return response()->json(['message' => 'Invalid PayPal webhook signature.'], 400);
        }

        $eventType = $request->input('event_type');
        $resource = $request->input('resource');
        $orderId = is_array($resource)
            ? data_get($resource, 'supplementary_data.related_ids.order_id')
            : null;

        if (!is_string($eventType) || !is_array($resource) || !is_string($orderId) || $orderId === '') {
            return response()->json(['status' => 'accepted']);
        }

        $transaction = PaymentTransaction::query()
            ->where('provider', 'paypal')
            ->where('provider_reference', $orderId)
            ->first();

        if ($transaction === null) {
            return response()->json(['status' => 'accepted']);
        }

        if ($eventType === 'CHECKOUT.ORDER.APPROVED') {
            return $this->captureApprovedOrder($transaction, $orderId);
        }

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED'
            && $this->paypal->webhookCaptureMatchesTransaction($resource, $transaction)) {
            $this->markPaid($transaction);
        }

        if ($eventType === 'PAYMENT.CAPTURE.REFUNDED' && $transaction->status === 'paid') {
            $transaction->update(['status' => 'refunded']);
        }

        if ($eventType === 'PAYMENT.CAPTURE.REVERSED' && $transaction->status === 'paid') {
            $transaction->update(['status' => 'reversed']);
        }

        return response()->json(['status' => 'accepted']);
    }

    private function captureApprovedOrder(PaymentTransaction $transaction, string $orderId): JsonResponse
    {
        if ($transaction->status !== 'initiated') {
            return response()->json(['status' => 'accepted']);
        }

        $accessToken = $this->paypal->accessToken();
        if ($accessToken === null) {
            return response()->json(['message' => 'PayPal authentication failed.'], 503);
        }

        $captureResponse = $this->paypal->captureOrder(
            $accessToken,
            $orderId,
            $this->captureRequestId($transaction)
        );

        if ($captureResponse !== null && $this->paypal->hasCompletedCapture($captureResponse, $transaction, $orderId)) {
            $this->markPaid($transaction);

            return response()->json(['status' => 'accepted']);
        }

        Log::warning('PayPal approved-order webhook could not be captured.', [
            'transaction_reference' => $transaction->reference,
        ]);

        return response()->json(['message' => 'PayPal order capture could not be confirmed.'], 503);
    }

    private function markPaid(PaymentTransaction $transaction): void
    {
        PaymentTransaction::query()
            ->whereKey($transaction->id)
            ->where('status', 'initiated')
            ->update(['status' => 'paid']);
    }

    private function captureRequestId(PaymentTransaction $transaction): string
    {
        return substr((string) $transaction->idempotency_key, 0, 100).'-capture';
    }
}
