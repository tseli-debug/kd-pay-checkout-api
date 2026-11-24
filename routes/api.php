<?php

use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\PaymentLinkController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Webhooks\MpesaWebhookController;
use App\Http\Controllers\Webhooks\PayPalWebhookController;
use App\Http\Middleware\AuthenticateApiClient;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/webhooks/mpesa/{callbackToken}', [MpesaWebhookController::class, 'store']);
    Route::post('/webhooks/paypal', [PayPalWebhookController::class, 'store']);

    Route::post('/oauth/token', [TokenController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/checkout/{publicId}', [CheckoutController::class, 'show']);
    Route::get('/checkout/{publicId}/paypal/return', [CheckoutController::class, 'paypalReturn']);
    Route::get('/checkout/{publicId}/paypal/cancel', [CheckoutController::class, 'paypalCancel']);
    Route::post('/checkout/{publicId}/transactions', [CheckoutController::class, 'initiate'])
        ->middleware('throttle:20,1');

    Route::middleware(AuthenticateApiClient::class)->group(function () {
        Route::delete('/oauth/token', [TokenController::class, 'revoke']);

        Route::get('/payment-links', [PaymentLinkController::class, 'index']);
        Route::post('/payment-links', [PaymentLinkController::class, 'store']);
        Route::get('/payment-links/{publicId}', [PaymentLinkController::class, 'show']);
        Route::delete('/payment-links/{publicId}', [PaymentLinkController::class, 'deactivate']);

        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/{reference}', [TransactionController::class, 'show']);
    });
});
