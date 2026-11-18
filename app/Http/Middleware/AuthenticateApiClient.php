<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if ($plainTextToken === null) {
            return response()->json(['message' => 'A bearer token is required.'], 401);
        }

        $token = ApiToken::query()
            ->with('apiClient.merchant')
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        $client = $token?->apiClient;
        $merchant = $client?->merchant;

        if ($token === null || $client === null || $merchant === null || !$client->is_active || !$merchant->is_active) {
            return response()->json(['message' => 'The bearer token is invalid or expired.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('api_token', $token);
        $request->attributes->set('api_client', $client);
        $request->setUserResolver(fn () => $merchant);

        return $next($request);
    }
}
