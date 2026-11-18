<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $transactions = PaymentTransaction::query()
            ->where('merchant_id', $request->user()->id)
            ->latest()
            ->paginate(25);

        return response()->json([
            'data' => collect($transactions->items())->map(fn (PaymentTransaction $transaction) => $this->present($transaction)),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $transaction = PaymentTransaction::query()
            ->where('merchant_id', $request->user()->id)
            ->where('reference', $reference)
            ->firstOrFail();

        return response()->json(['data' => $this->present($transaction)]);
    }

    private function present(PaymentTransaction $transaction): array
    {
        return [
            'reference' => $transaction->reference,
            'payment_link_id' => $transaction->paymentLink?->public_id,
            'provider' => $transaction->provider,
            'provider_reference' => $transaction->provider_reference,
            'customer_phone' => $transaction->customer_phone,
            'amount' => $transaction->amount,
            'fee_amount' => $transaction->fee_amount,
            'net_amount' => $transaction->net_amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status,
            'created_at' => $transaction->created_at->toISOString(),
        ];
    }
}
