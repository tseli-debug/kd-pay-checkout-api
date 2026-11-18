<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'grant_type' => ['required', 'in:client_credentials'],
            'client_id' => ['required', 'string', 'max:80'],
            'client_secret' => ['required', 'string', 'max:255'],
        ]);

        $client = ApiClient::query()
            ->with('merchant')
            ->where('client_id', $validated['client_id'])
            ->first();

        if (
            $client === null
            || !$client->is_active
            || $client->merchant === null
            || !$client->merchant->is_active
            || !Hash::check($validated['client_secret'], $client->client_secret_hash)
        ) {
            return response()->json(['message' => 'The client credentials are invalid.'], 401);
        }

        $plainTextToken = Str::random(80);
        $lifetimeSeconds = config('kdpay.token_lifetime_minutes') * 60;

        $client->tokens()->create([
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => now()->addSeconds($lifetimeSeconds),
        ]);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $plainTextToken,
            'expires_in' => $lifetimeSeconds,
        ]);
    }

    public function revoke(Request $request): JsonResponse
    {
        $request->attributes->get('api_token')->forceFill([
            'revoked_at' => now(),
        ])->save();

        return response()->json(['message' => 'Token revoked.']);
    }
}
