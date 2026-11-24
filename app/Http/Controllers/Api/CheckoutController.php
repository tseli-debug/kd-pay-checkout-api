<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentLink;
use App\Models\PaymentTransaction;
use App\Services\PayPalGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function __construct(private readonly PayPalGateway $paypal)
    {
    }

    public function show(string $publicId): JsonResponse
    {
        $link = PaymentLink::query()
            ->with('merchant')
            ->where('public_id', $publicId)
            ->first();

        if ($link === null || $link->status !== 'active') {
            return response()->json(['message' => 'This payment link is unavailable.'], 404);
        }

        if ($link->expires_at !== null && $link->expires_at->isPast()) {
            return response()->json(['message' => 'This payment link has expired.'], 410);
        }

        return response()->json([
            'data' => [
                'id' => $link->public_id,
                'merchant' => [
                    'name' => $link->merchant->business_name ?: $link->merchant->name,
                ],
                'title' => $link->title,
                'description' => $link->description,
                'amount' => $link->amount,
                'currency' => $link->currency,
                'expires_at' => $link->expires_at?->toISOString(),
                'payment_methods' => collect(config('kdpay.providers'))
                    ->map(fn (array $provider, string $code) => [
                        'code' => $code,
                        'name' => $provider['name'],
                        'available' => $provider['enabled'],
                    ])
                    ->values(),
            ],
        ]);
    }

    public function initiate(Request $request, string $publicId): JsonResponse
    {
        $link = PaymentLink::query()
            ->where('public_id', $publicId)
            ->where('status', 'active')
            ->first();

        if ($link === null) {
            return response()->json(['message' => 'This payment link is unavailable.'], 404);
        }

        if ($link->expires_at !== null && $link->expires_at->isPast()) {
            return response()->json(['message' => 'This payment link has expired.'], 410);
        }

        $validated = $request->validate([
            'provider' => ['required', 'in:mpesa,paypal,ecocash'],
            'phone' => ['required_if:provider,mpesa,ecocash', 'nullable', 'string', 'regex:/^\\+?[0-9]{10,14}$/'],
            'amount' => $link->amount === null
                ? ['required', 'decimal:0,2', 'min:0.01', 'max:9999999999.99']
                : ['prohibited'],
        ]);

        $amount = $link->amount ?? (float) ($validated['amount'] ?? 0);

        if ($validated['provider'] === 'paypal') {
            return $this->initiatePaypal($link, $amount);
        }

        if ($validated['provider'] !== 'mpesa') {
            return response()->json([
                'code' => 'payment_provider_unavailable',
                'message' => 'The selected mobile-money provider is not configured yet.',
            ], 503);
        }

        if (!config('kdpay.mpesa.enabled')) {
            return response()->json([
                'code' => 'payment_provider_unavailable',
                'message' => 'M-Pesa sandbox is not enabled yet.',
            ], 503);
        }

        $apiKey = (string) config('kdpay.mpesa.api_key');
        $publicKey = (string) config('kdpay.mpesa.public_key');
        $serviceProviderCode = (string) config('kdpay.mpesa.service_provider_code');

        if ($apiKey === '' || $publicKey === '' || $serviceProviderCode === '') {
            return response()->json([
                'code' => 'payment_provider_unavailable',
                'message' => 'M-Pesa integration configuration is incomplete.',
            ], 503);
        }

        if ($link->currency !== 'LSL') {
            return response()->json([
                'code' => 'payment_currency_not_supported',
                'message' => 'M-Pesa payments require an LSL payment link.',
            ], 422);
        }

        $callbackBaseUrl = trim((string) config('kdpay.mpesa.callback_url'));
        $callbackToken = trim((string) config('kdpay.mpesa.callback_token'));
        if ($callbackBaseUrl === '' || $callbackToken === '') {
            return response()->json([
                'code' => 'payment_provider_unavailable',
                'message' => 'M-Pesa callback configuration is incomplete.',
            ], 503);
        }

        $bearerToken = $this->createMpesaBearerToken($apiKey, $publicKey);
        if ($bearerToken === null) {
            return response()->json([
                'code' => 'mpesa_session_key_failed',
                'message' => 'Could not generate an M-Pesa session key.',
            ], 502);
        }

        $environment = config('kdpay.mpesa.environment') === 'production'
            ? 'openapi'
            : 'sandbox';

        try {
            $sessionResponse = Http::acceptJson()
                ->withHeader('Origin', '*')
                ->timeout(30)
                ->withToken($bearerToken)
                ->get('https://openapi.m-pesa.com/'.$environment.'/ipg/v2/vodacomLES/getSession/');
        } catch (ConnectionException $exception) {
            Log::warning('M-Pesa session-key request connection failed.', [
                'environment' => $environment,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'code' => 'mpesa_session_key_failed',
                'message' => 'Could not connect to M-Pesa for a session key.',
            ], 502);
        }

        if (!$sessionResponse->successful() || $sessionResponse->json('output_ResponseCode') !== 'INS-0') {
            Log::warning('M-Pesa session-key request rejected.', [
                'status' => $sessionResponse->status(),
                'environment' => $environment,
                'response_code' => $sessionResponse->json('output_ResponseCode'),
            ]);

            return response()->json([
                'code' => 'mpesa_session_key_failed',
                'message' => 'M-Pesa rejected the session-key request.',
            ], 502);
        }

        $sessionKey = $sessionResponse->json('output_SessionID');
        if (!is_string($sessionKey) || $sessionKey === '') {
            return response()->json([
                'code' => 'mpesa_session_key_failed',
                'message' => 'M-Pesa did not return a session key.',
            ], 502);
        }

        $reference = strtoupper(substr(str_replace('-', '', Str::uuid()->toString()), 0, 20));
        $thirdPartyReference = substr(str_replace('-', '', Str::uuid()->toString()), 0, 40);
        $phone = ltrim($validated['phone'], '+');
        $callbackUrl = rtrim($callbackBaseUrl, '/').'/'.rawurlencode($callbackToken);
        $transaction = $link->transactions()->create([
            'merchant_id' => $link->merchant_id,
            'reference' => $reference,
            'provider' => 'mpesa',
            'customer_phone' => $phone,
            'amount' => $amount,
            'currency' => 'LSL',
            'status' => 'initiated',
            'idempotency_key' => $thirdPartyReference,
        ]);

        $payload = [
            'input_Amount' => number_format((float) $amount, 2, '.', ''),
            'input_Country' => 'LES',
            'input_Currency' => 'LSL',
            'input_CustomerMSISDN' => $phone,
            'input_ServiceProviderCode' => $serviceProviderCode,
            'input_TransactionReference' => $reference,
            'input_ThirdPartyConversationID' => $thirdPartyReference,
            'input_PurchasedItemsDesc' => substr($link->title, 0, 256),
            'input_ResponseURL' => $callbackUrl,
        ];

        try {
            $paymentResponse = Http::acceptJson()
                ->withHeader('Origin', '*')
                ->timeout(30)
                ->withToken($sessionKey)
                ->post(
                    'https://openapi.m-pesa.com/'.$environment.'/ipg/v2/vodacomLES/c2bPayment/singleStage/',
                    $payload
                );
        } catch (ConnectionException $exception) {
            Log::warning('M-Pesa payment request connection failed.', [
                'transaction_reference' => $transaction->reference,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'data' => [
                    'reference' => $transaction->reference,
                    'provider' => 'mpesa',
                    'status' => 'initiated',
                ],
                'code' => 'mpesa_payment_status_unknown',
                'message' => 'M-Pesa did not confirm whether the payment request was received.',
            ], 202);
        }

        $paymentBody = $paymentResponse->json();
        if (!$paymentResponse->successful() || ($paymentBody['output_ResponseCode'] ?? null) !== 'INS-0') {
            $transaction->update(['status' => 'failed']);

            return response()->json([
                'code' => $paymentBody['output_ResponseCode'] ?? 'mpesa_request_failed',
                'message' => $paymentBody['output_ResponseDesc'] ?? 'M-Pesa rejected the payment request.',
            ], 502);
        }

        $conversationId = $paymentBody['output_ConversationID'] ?? null;
        $status = is_string($paymentBody['output_TransactionID'] ?? null)
            && $paymentBody['output_TransactionID'] !== ''
            ? 'completed'
            : 'initiated';

        $transaction->update([
            'provider_reference' => $conversationId,
            'status' => $status,
        ]);

        return response()->json([
            'data' => [
                'reference' => $transaction->reference,
                'provider' => 'mpesa',
                'status' => $status,
                'conversation_id' => $conversationId,
                'response_code' => $paymentBody['output_ResponseCode'] ?? null,
                'response_description' => $paymentBody['output_ResponseDesc'] ?? null,
            ],
        ], 201);
    }

    public function paypalReturn(Request $request, string $publicId): RedirectResponse|JsonResponse
    {
        $reference = $request->query('reference');
        $orderId = $request->query('token');
        $link = PaymentLink::query()->where('public_id', $publicId)->first();

        if (!is_string($reference) || !is_string($orderId) || $link === null) {
            return response()->json(['message' => 'The PayPal return could not be matched to a checkout.'], 404);
        }

        $transaction = $link->transactions()
            ->where('reference', $reference)
            ->where('provider', 'paypal')
            ->where('provider_reference', $orderId)
            ->first();

        if ($transaction === null) {
            return response()->json(['message' => 'The PayPal order was not found.'], 404);
        }

        if ($transaction->status === 'paid') {
            return $this->paypalRedirect($link, 'success', $reference);
        }

        if (!$this->paypal->isConfigured()) {
            return $this->paypalRedirect($link, 'failed', $reference);
        }

        $accessToken = $this->paypal->accessToken();
        if ($accessToken === null) {
            return $this->paypalRedirect($link, 'failed', $reference);
        }

        $captureResponse = $this->paypal->captureOrder(
            $accessToken,
            $orderId,
            $this->paypalCaptureRequestId($transaction)
        );
        $captureMatchesTransaction = $captureResponse !== null
            && $this->paypal->hasCompletedCapture($captureResponse, $transaction, $orderId);

        if (!$captureMatchesTransaction) {
            $orderResponse = $this->paypal->getOrder($accessToken, $orderId);
            $captureMatchesTransaction = $orderResponse !== null
                && $this->paypal->hasCompletedCapture($orderResponse, $transaction, $orderId);
        }

        if (!$captureMatchesTransaction) {
            Log::warning('PayPal capture was not completed for the expected amount.', [
                'transaction_reference' => $reference,
                'order_id' => $orderId,
            ]);

            return $this->paypalRedirect($link, 'failed', $reference);
        }

        PaymentTransaction::query()
            ->whereKey($transaction->id)
            ->where('status', 'initiated')
            ->update(['status' => 'paid']);

        return $this->paypalRedirect($link, 'success', $reference);
    }

    public function paypalCancel(Request $request, string $publicId): RedirectResponse|JsonResponse
    {
        $reference = $request->query('reference');
        $orderId = $request->query('token');
        $link = PaymentLink::query()->where('public_id', $publicId)->first();

        if (!is_string($reference) || !is_string($orderId) || $link === null) {
            return response()->json(['message' => 'The PayPal cancellation could not be matched to a checkout.'], 404);
        }

        $transaction = $link->transactions()
            ->where('reference', $reference)
            ->where('provider', 'paypal')
            ->where('provider_reference', $orderId)
            ->first();

        if ($transaction === null) {
            return response()->json(['message' => 'The PayPal order was not found.'], 404);
        }

        if ($transaction->status === 'initiated') {
            $transaction->update(['status' => 'cancelled']);
        }

        return $this->paypalRedirect($link, 'cancelled', $reference);
    }

    private function initiatePaypal(PaymentLink $link, float $amount): JsonResponse
    {
        if (!config('kdpay.paypal.enabled')) {
            return response()->json([
                'code' => 'payment_provider_unavailable',
                'message' => 'PayPal is not enabled.',
            ], 503);
        }

        if ($link->currency !== 'USD') {
            return response()->json([
                'code' => 'payment_currency_not_supported',
                'message' => 'PayPal payments currently require a USD payment link.',
            ], 422);
        }

        if (!$this->paypal->isConfigured()) {
            return response()->json([
                'code' => 'payment_provider_unavailable',
                'message' => 'PayPal integration configuration is incomplete.',
            ], 503);
        }

        $accessToken = $this->paypal->accessToken();
        if ($accessToken === null) {
            return response()->json([
                'code' => 'paypal_auth_failed',
                'message' => 'Could not authenticate with PayPal.',
            ], 502);
        }

        $reference = strtoupper(substr(str_replace('-', '', Str::uuid()->toString()), 0, 20));
        $returnUrl = rtrim((string) config('app.url'), '/')
            .'/api/v1/checkout/'.$link->public_id.'/paypal/return?reference='.$reference;
        $cancelUrl = rtrim((string) config('app.url'), '/')
            .'/api/v1/checkout/'.$link->public_id.'/paypal/cancel?reference='.$reference;
        $transaction = $link->transactions()->create([
            'merchant_id' => $link->merchant_id,
            'reference' => $reference,
            'provider' => 'paypal',
            'amount' => $amount,
            'currency' => $link->currency,
            'status' => 'initiated',
            'idempotency_key' => $reference,
        ]);

        $orderResponse = $this->paypal->createOrder($accessToken, $reference, [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $reference,
                'custom_id' => $reference,
                'description' => substr($link->title, 0, 127),
                'amount' => [
                    'currency_code' => $link->currency,
                    'value' => number_format($amount, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'user_action' => 'PAY_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ]);

        if ($orderResponse === null) {
            $transaction->update(['status' => 'failed']);

            return response()->json([
                'code' => 'paypal_order_failed',
                'message' => 'Could not create a PayPal order.',
            ], 502);
        }

        $orderId = $orderResponse->json('id');
        $approvalUrl = collect($orderResponse->json('links', []))
            ->first(fn (array $link) => in_array($link['rel'] ?? null, ['approve', 'payer-action'], true))['href'] ?? null;

        if (!$orderResponse->successful() || !is_string($orderId) || !is_string($approvalUrl)) {
            $transaction->update(['status' => 'failed']);

            Log::warning('PayPal rejected the order request.', [
                'payment_link_id' => $link->id,
                'status' => $orderResponse->status(),
                'paypal_error' => $orderResponse->json('name'),
            ]);

            return response()->json([
                'code' => 'paypal_order_failed',
                'message' => 'PayPal rejected the order request.',
            ], 502);
        }

        $transaction->update(['provider_reference' => $orderId]);

        return response()->json([
            'data' => [
                'reference' => $transaction->reference,
                'provider' => 'paypal',
                'status' => 'initiated',
                'approval_url' => $approvalUrl,
                'currency' => $transaction->currency,
            ],
        ], 201);
    }

    private function paypalCaptureRequestId(PaymentTransaction $transaction): string
    {
        $key = $transaction->idempotency_key ?: $transaction->reference;

        return substr($key, 0, 100).'-capture';
    }

    private function paypalRedirect(PaymentLink $link, string $status, string $reference): RedirectResponse
    {
        $target = $status === 'success'
            ? ($link->success_url ?: rtrim(config('kdpay.checkout_url'), '/').'/'.$link->public_id)
            : ($link->cancel_url ?: rtrim(config('kdpay.checkout_url'), '/').'/'.$link->public_id);
        $fragment = '';
        $fragmentPosition = strpos($target, '#');

        if ($fragmentPosition !== false) {
            $fragment = substr($target, $fragmentPosition);
            $target = substr($target, 0, $fragmentPosition);
        }

        $separator = strpos($target, '?') === false ? '?' : '&';
        $query = http_build_query([
            'payment_status' => $status,
            'transaction_reference' => $reference,
        ]);

        return redirect()->away($target.$separator.$query.$fragment);
    }

    private function createMpesaBearerToken(string $apiKey, string $publicKey): ?string
    {
        $pem = $this->normalizeMpesaPublicKey($publicKey);

        $encrypted = null;
        $success = openssl_public_encrypt($apiKey, $encrypted, $pem, OPENSSL_PKCS1_PADDING);

        return $success ? base64_encode($encrypted) : null;
    }

    private function normalizeMpesaPublicKey(string $publicKey): string
    {
        $publicKey = trim(str_replace("\r\n", "\n", $publicKey));

        if (preg_match('/^-----BEGIN [^-]+-----/', $publicKey) === 1) {
            return $publicKey;
        }

        $body = preg_replace('/^-----BEGIN [^-]+-----\n?|-----END [^-]+-----$/', '', $publicKey);
        $body = preg_replace('/\s+/', '', (string) $body);

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split($body, 64, "\n")
            ."-----END PUBLIC KEY-----";
    }
}
