<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountContractingCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/** @extends Factory<AccountContractingCertificate> */
class AccountContractingCertificateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(), 'document' => '12345678000195',
            'subject' => '/CN=Platform:12345678000195', 'serial_number' => '123',
            'valid_from' => now()->subDay(), 'valid_until' => now()->addYear(),
            'certificate_encrypted' => Crypt::encryptString('fixture-only'),
            'password_encrypted' => Crypt::encryptString('fixture-password'), 'revision' => 1,
        ];
    }
}
