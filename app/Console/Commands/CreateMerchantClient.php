<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateMerchantClient extends Command
{
    protected $signature = 'kd-pay:merchant
        {business_name : The merchant business name}
        {email : The merchant account email}
        {--phone= : The merchant phone number}';

    protected $description = 'Create a merchant account and one-time API credentials';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));

        if (User::query()->where('email', $email)->exists()) {
            $this->error('A merchant account with that email already exists.');

            return self::FAILURE;
        }

        $clientId = 'kdp_'.Str::lower(Str::random(32));
        $clientSecret = Str::random(64);

        DB::transaction(function () use ($email, $clientId, $clientSecret): void {
            $merchant = User::query()->create([
                'name' => $this->argument('business_name'),
                'business_name' => $this->argument('business_name'),
                'phone' => $this->option('phone'),
                'email' => $email,
                'password' => Str::random(64),
                'is_active' => true,
            ]);

            $merchant->apiClients()->create([
                'client_id' => $clientId,
                'client_secret_hash' => Hash::make($clientSecret),
                'is_active' => true,
            ]);
        });

        $this->info('Merchant and API client created. Copy the secret now; it cannot be retrieved later.');
        $this->line('Client ID: '.$clientId);
        $this->line('Client secret: '.$clientSecret);

        return self::SUCCESS;
    }
}
