<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpReceiptProof;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OfficeErpReceiptProof> */
class OfficeErpReceiptProofFactory extends Factory
{
    protected $model = OfficeErpReceiptProof::class;

    public function definition(): array
    {
        $bytes = "%PDF-1.4\nFactory receipt proof\n%%EOF\n";
        $id = (string) Str::uuid();

        return [
            'id' => $id,
            'account_id' => Account::factory(),
            'actor_user_id' => User::factory(),
            'receipt_id' => null,
            'original_name' => 'comprovante.pdf',
            'mime_type' => 'application/pdf',
            'byte_length' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'path' => 'office-erp/receipt-proofs/factory/'.$id.'.pdf',
            'expires_at' => now()->addHours(24),
            'claimed_at' => null,
        ];
    }
}
