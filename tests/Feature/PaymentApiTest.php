<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_credentials_issue_a_bearer_token_that_can_create_a_payment_link(): void
    {
        [$merchant, $clientSecret] = $this->createMerchantClient();

        $tokenResponse = $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $merchant->apiClients()->firstOrFail()->client_id,
            'client_secret' => $clientSecret,
        ]);

        $tokenResponse->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_in', 3600);

        $accessToken = $tokenResponse->json('access_token');
        $this->assertDatabaseHas('api_tokens', [
            'token_hash' => hash('sha256', $accessToken),
        ]);

        $linkResponse = $this->withToken($accessToken)->postJson('/api/v1/payment-links', [
            'title' => 'Wireless Earbuds Pro',
            'amount' => '1299.00',
        ]);

        $linkResponse->assertCreated()
            ->assertJsonPath('data.title', 'Wireless Earbuds Pro')
            ->assertJsonPath('data.amount', '1299.00')
            ->assertJsonPath('data.currency', 'LSL')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('payment_links', [
            'merchant_id' => $merchant->id,
            'amount' => '1299.00',
        ]);
    }

    public function test_invalid_client_credentials_do_not_issue_a_token(): void
    {
        [$merchant] = $this->createMerchantClient();

        $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $merchant->apiClients()->firstOrFail()->client_id,
            'client_secret' => 'incorrect-secret',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_expired_tokens_cannot_access_merchant_endpoints(): void
    {
        [$merchant] = $this->createMerchantClient();
        $plainToken = Str::random(80);
        $client = $merchant->apiClients()->firstOrFail();

        ApiToken::query()->create([
            'api_client_id' => $client->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->subSecond(),
        ]);

        $this->withToken($plainToken)
            ->getJson('/api/v1/payment-links')
            ->assertUnauthorized();
    }

    public function test_merchants_cannot_access_another_merchants_payment_link(): void
    {
        [$merchant, $clientSecret] = $this->createMerchantClient();
        $otherMerchant = User::factory()->create();
        $otherLink = $otherMerchant->paymentLinks()->create([
            'public_id' => (string) Str::uuid(),
            'title' => 'Private link',
            'currency' => 'LSL',
            'status' => 'active',
        ]);
        $token = $this->issueToken($merchant, $clientSecret);

        $this->withToken($token)
            ->getJson('/api/v1/payment-links/'.$otherLink->public_id)
            ->assertNotFound();
    }

    public function test_checkout_reports_provider_unavailability_without_creating_a_transaction(): void
    {
        $merchant = User::factory()->create(['business_name' => 'TechHive Store']);
        $link = $merchant->paymentLinks()->create([
            'public_id' => (string) Str::uuid(),
            'title' => 'Wireless Earbuds Pro',
            'amount' => '1299.00',
            'currency' => 'LSL',
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/checkout/'.$link->public_id)
            ->assertOk()
            ->assertJsonPath('data.merchant.name', 'TechHive Store')
            ->assertJsonPath('data.payment_methods.0.available', false);

        $this->postJson('/api/v1/checkout/'.$link->public_id.'/transactions', [
            'provider' => 'mpesa',
            'phone' => '+26658123456',
        ])->assertStatus(503)
            ->assertJsonPath('code', 'payment_provider_unavailable');

        $this->assertDatabaseCount('payment_transactions', 0);
    }

    private function createMerchantClient(): array
    {
        $merchant = User::factory()->create([
            'business_name' => 'TechHive Store',
            'is_active' => true,
        ]);
        $clientSecret = Str::random(64);

        $merchant->apiClients()->create([
            'client_id' => 'kdp_'.Str::lower(Str::random(32)),
            'client_secret_hash' => Hash::make($clientSecret),
            'is_active' => true,
        ]);

        return [$merchant, $clientSecret];
    }

    private function issueToken(User $merchant, string $clientSecret): string
    {
        $response = $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $merchant->apiClients()->firstOrFail()->client_id,
            'client_secret' => $clientSecret,
        ]);

        $response->assertOk();

        return $response->json('access_token');
    }
}
