<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
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
            'provider' => ['required', 'in:mpesa,ecocash'],
            'phone' => ['required', 'string', 'regex:/^\\+?[0-9]{10,14}$/'],
            'amount' => $link->amount === null
                ? ['required', 'decimal:0,2', 'min:0.01', 'max:9999999999.99']
                : ['prohibited'],
        ]);

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
        $sessionResponse = Http::acceptJson()
            ->withHeader('Origin', '*')
            ->timeout(30)
            ->withToken($bearerToken)
            ->get('https://openapi.m-pesa.com/'.$environment.'/ipg/v2/vodacomLES/getSession/');

        if (!$sessionResponse->successful()) {
            Log::warning('M-Pesa session-key request rejected.', [
                'status' => $sessionResponse->status(),
                'environment' => $environment,
                'response' => $sessionResponse->json(),
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
        $amount = $link->amount ?? (float) $validated['amount'];
        $phone = ltrim($validated['phone'], '+');

        $payload = [
            'input_Amount' => number_format((float) $amount, 2, '.', ''),
            'input_Country' => 'LES',
            'input_Currency' => 'LSL',
            'input_CustomerMSISDN' => $phone,
            'input_ServiceProviderCode' => $serviceProviderCode,
            'input_TransactionReference' => $reference,
            'input_ThirdPartyConversationID' => $thirdPartyReference,
            'input_PurchasedItemsDesc' => substr($link->title, 0, 256),
        ];

        $callbackUrl = (string) config('kdpay.mpesa.callback_url');
        if ($callbackUrl !== '') {
            $payload['input_ResponseURL'] = $callbackUrl;
        }

        $paymentResponse = Http::acceptJson()
            ->withHeader('Origin', '*')
            ->timeout(30)
            ->withToken($sessionKey)
            ->post(
                'https://openapi.m-pesa.com/'.$environment.'/ipg/v2/vodacomLES/c2bPayment/singleStage/',
                $payload
            );

        $paymentBody = $paymentResponse->json();
        if (!$paymentResponse->successful()) {
            return response()->json([
                'code' => $paymentBody['output_ResponseCode'] ?? 'mpesa_request_failed',
                'message' => $paymentBody['output_ResponseDesc'] ?? 'M-Pesa rejected the payment request.',
            ], $paymentResponse->status());
        }

        $transaction = $link->transactions()->create([
            'merchant_id' => $link->merchant_id,
            'reference' => $reference,
            'provider' => 'mpesa',
            'provider_reference' => $paymentBody['output_ConversationID'] ?? null,
            'customer_phone' => $phone,
            'amount' => $amount,
            'currency' => 'LSL',
            'status' => 'initiated',
            'idempotency_key' => $thirdPartyReference,
        ]);

        return response()->json([
            'data' => [
                'reference' => $transaction->reference,
                'provider' => 'mpesa',
                'status' => 'initiated',
                'conversation_id' => $paymentBody['output_ConversationID'] ?? null,
                'response_code' => $paymentBody['output_ResponseCode'] ?? null,
                'response_description' => $paymentBody['output_ResponseDesc'] ?? null,
            ],
        ], 201);
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
