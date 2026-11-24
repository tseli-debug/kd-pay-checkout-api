<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayPalGateway
{
    public function isConfigured(): bool
    {
        return in_array(config('kdpay.paypal.mode'), ['sandbox', 'live'], true)
            && is_string(config('kdpay.paypal.client_id'))
            && trim(config('kdpay.paypal.client_id')) !== ''
            && is_string(config('kdpay.paypal.client_secret'))
            && trim(config('kdpay.paypal.client_secret')) !== '';
    }

    public function accessToken(): ?string
    {
        try {
            $response = Http::acceptJson()
                ->asForm()
                ->timeout(30)
                ->withBasicAuth(
                    (string) config('kdpay.paypal.client_id'),
                    (string) config('kdpay.paypal.client_secret')
                )
                ->post($this->apiUrl().'/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('PayPal authentication connection failed.', [
                'exception' => $exception::class,
            ]);

            return null;
        }

        $accessToken = $response->json('access_token');
        if (!$response->successful() || !is_string($accessToken) || $accessToken === '') {
            Log::warning('PayPal authentication was rejected.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        return $accessToken;
    }

    public function createOrder(string $accessToken, string $requestId, array $payload): ?Response
    {
        try {
            return Http::acceptJson()
                ->timeout(30)
                ->withToken($accessToken)
                ->withHeader('PayPal-Request-Id', $requestId)
                ->post($this->apiUrl().'/v2/checkout/orders', $payload);
        } catch (ConnectionException $exception) {
            Log::warning('PayPal order creation connection failed.', [
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    public function captureOrder(string $accessToken, string $orderId, string $requestId): ?Response
    {
        try {
            return Http::acceptJson()
                ->timeout(30)
                ->withToken($accessToken)
                ->withHeader('PayPal-Request-Id', $requestId)
                ->post($this->apiUrl().'/v2/checkout/orders/'.rawurlencode($orderId).'/capture', []);
        } catch (ConnectionException $exception) {
            Log::warning('PayPal order capture connection failed.', [
                'order_id' => $orderId,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    public function getOrder(string $accessToken, string $orderId): ?Response
    {
        try {
            return Http::acceptJson()
                ->timeout(30)
                ->withToken($accessToken)
                ->get($this->apiUrl().'/v2/checkout/orders/'.rawurlencode($orderId));
        } catch (ConnectionException $exception) {
            Log::warning('PayPal order lookup connection failed.', [
                'order_id' => $orderId,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    public function verifyWebhook(Request $request): bool
    {
        $webhookId = trim((string) config('kdpay.paypal.webhook_id'));
        if (!$this->isConfigured() || $webhookId === '') {
            return false;
        }

        $requiredHeaders = [
            'auth_algo' => $request->header('paypal-auth-algo'),
            'cert_url' => $request->header('paypal-cert-url'),
            'transmission_id' => $request->header('paypal-transmission-id'),
            'transmission_sig' => $request->header('paypal-transmission-sig'),
            'transmission_time' => $request->header('paypal-transmission-time'),
        ];

        if (collect($requiredHeaders)->contains(fn ($header) => !is_string($header) || $header === '')) {
            return false;
        }

        $accessToken = $this->accessToken();
        if ($accessToken === null) {
            return false;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(30)
                ->withToken($accessToken)
                ->post($this->apiUrl().'/v1/notifications/verify-webhook-signature', [
                    ...$requiredHeaders,
                    'webhook_id' => $webhookId,
                    'webhook_event' => $request->json()->all(),
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('PayPal webhook verification connection failed.', [
                'exception' => $exception::class,
            ]);

            return false;
        }

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    public function hasCompletedCapture(Response $response, PaymentTransaction $transaction, string $orderId): bool
    {
        $capture = $response->json('purchase_units.0.payments.captures.0');

        return $response->successful()
            && $response->json('id') === $orderId
            && $response->json('status') === 'COMPLETED'
            && data_get($capture, 'status') === 'COMPLETED'
            && data_get($capture, 'amount.currency_code') === $transaction->currency
            && data_get($capture, 'amount.value') === $transaction->amount;
    }

    public function webhookCaptureMatchesTransaction(array $resource, PaymentTransaction $transaction): bool
    {
        return data_get($resource, 'status') === 'COMPLETED'
            && data_get($resource, 'amount.currency_code') === $transaction->currency
            && data_get($resource, 'amount.value') === $transaction->amount;
    }

    private function apiUrl(): string
    {
        return config('kdpay.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}
