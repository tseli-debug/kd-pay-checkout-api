# KD Pay API

Laravel API for merchant payment links, public checkout details, and transaction lookups. The initial release is scoped to Lesotho and uses LSL.

## Requirements

- PHP 8.3+
- Composer
- SQLite for local development or PostgreSQL for deployment

## Local setup

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Set `KD_PAY_CHECKOUT_URL` to the public checkout application's base URL.

Create a merchant API client. The client secret is displayed only once:

```powershell
php artisan kd-pay:merchant "TechHive Store" merchant@example.test --phone="+26658123456"
```

Exchange the client credentials for a one-hour bearer token:

```http
POST /api/v1/oauth/token
Content-Type: application/json

{
  "grant_type": "client_credentials",
  "client_id": "kdp_...",
  "client_secret": "..."
}
```

Store the client secret securely on the merchant server and use the returned access token as `Authorization: Bearer <token>`. Access tokens are stored as SHA-256 hashes, expire after one hour, and can be revoked with `DELETE /api/v1/oauth/token`.

## Endpoints

Merchant endpoints require a bearer token:

- `GET /api/v1/payment-links`
- `POST /api/v1/payment-links`
- `GET /api/v1/payment-links/{id}`
- `DELETE /api/v1/payment-links/{id}` — deactivates the link
- `GET /api/v1/transactions`
- `GET /api/v1/transactions/{reference}`

Public checkout endpoints:

- `GET /api/v1/checkout/{id}`
- `POST /api/v1/checkout/{id}/transactions`

Payment-link creation accepts a fixed `amount` or omits it for a buyer-entered amount. Currency is currently restricted to `LSL`. Amounts are persisted as `DECIMAL(12,2)`.

The checkout API currently reports M-Pesa and EcoCash as unavailable. Initiation returns `503 payment_provider_unavailable` until provider sandbox credentials and the corresponding carrier connectors are configured; it will not report a payment as successful or create a transaction before a provider accepts the request.

## Tests

```powershell
php artisan test
```
