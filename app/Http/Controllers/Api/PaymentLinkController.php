<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentLink;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentLinkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $links = PaymentLink::query()
            ->where('merchant_id', $request->user()->id)
            ->latest()
            ->paginate(25);

        return response()->json([
            'data' => collect($links->items())->map(fn (PaymentLink $link) => $this->present($link)),
            'meta' => [
                'current_page' => $links->currentPage(),
                'last_page' => $links->lastPage(),
                'per_page' => $links->perPage(),
                'total' => $links->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => ['nullable', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'currency' => ['sometimes', 'in:LSL'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'success_url' => ['nullable', 'url', 'max:2048'],
            'cancel_url' => ['nullable', 'url', 'max:2048'],
        ]);

        /** @var User $merchant */
        $merchant = $request->user();
        $link = $merchant->paymentLinks()->create([
            ...$validated,
            'public_id' => (string) Str::uuid(),
            'currency' => $validated['currency'] ?? config('kdpay.currency'),
            'status' => 'active',
        ]);

        return response()->json(['data' => $this->present($link)], 201);
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        $link = PaymentLink::query()
            ->where('merchant_id', $request->user()->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return response()->json(['data' => $this->present($link)]);
    }

    public function deactivate(Request $request, string $publicId): JsonResponse
    {
        $link = PaymentLink::query()
            ->where('merchant_id', $request->user()->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $link->update(['status' => 'deactivated']);

        return response()->json(['data' => $this->present($link->refresh())]);
    }

    private function present(PaymentLink $link): array
    {
        return [
            'id' => $link->public_id,
            'title' => $link->title,
            'description' => $link->description,
            'amount' => $link->amount,
            'currency' => $link->currency,
            'status' => $link->status,
            'expires_at' => $link->expires_at?->toISOString(),
            'checkout_url' => rtrim(config('kdpay.checkout_url'), '/').'/'.$link->public_id,
            'created_at' => $link->created_at->toISOString(),
        ];
    }
}
