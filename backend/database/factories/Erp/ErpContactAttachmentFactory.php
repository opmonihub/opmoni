<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpContactAttachment;
use App\Models\Erp\ErpContactNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErpContactAttachment>
 */
class ErpContactAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'erp_contact_note_id' => ErpContactNote::factory(),
            'erp_company_id' => fn (array $attributes): int => (int) ErpContactNote::query()
                ->whereKey($attributes['erp_contact_note_id'])
                ->value('erp_company_id'),
            'original_name' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'byte_length' => 9,
            'sha256' => hash('sha256', '%PDF-test'),
            'storage_key' => bin2hex(random_bytes(32)),
            'status' => 'unavailable',
        ];
    }
}
