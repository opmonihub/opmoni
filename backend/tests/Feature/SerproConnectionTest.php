<?php

namespace Tests\Feature;

use App\Models\SerproConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SerproConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_the_secret_encrypted_and_never_returns_it(): void
    {
        $connection = SerproConnection::factory()->create([
            'consumer_secret_encrypted' => Crypt::encryptString('super-secret'),
        ]);

        $this->assertNotSame('super-secret', $connection->getRawOriginal('consumer_secret_encrypted'));
        $this->assertSame('super-secret', $connection->consumerSecret());
        $this->assertArrayNotHasKey('consumer_secret', $connection->safeMetadata());
        $this->assertArrayNotHasKey('certificate_encrypted', $connection->safeMetadata());
        $this->assertArrayNotHasKey('certificate_password_encrypted', $connection->safeMetadata());
    }

    public function test_safe_metadata_reports_configured_state(): void
    {
        $metadata = SerproConnection::factory()->create()->safeMetadata();

        $this->assertTrue($metadata['configured']);
        $this->assertSame('12345678000195', $metadata['contratante_numero']);
    }

    public function test_current_returns_null_when_absent(): void
    {
        $this->assertNull(SerproConnection::current());
    }

    public function test_services_map_has_correct_shape(): void
    {
        $services = config('integra-contador.services');

        $this->assertIsArray($services);
        $this->assertNotEmpty($services);

        foreach ($services as $key => $entry) {
            $this->assertIsString($key);
            $this->assertNotEmpty($key);
            $this->assertIsString($entry['path']);
            $this->assertNotEmpty($entry['path'], "path for {$key} must not be empty");
            $this->assertIsString($entry['versaoSistema']);
            $this->assertNotEmpty($entry['versaoSistema'], "versaoSistema for {$key} must not be empty");
            $this->assertIsBool($entry['billable'], "billable for {$key} must be a bool");
        }

        $hasFreeEntry = collect($services)->contains(fn ($entry) => $entry['billable'] === false);
        $this->assertTrue($hasFreeEntry, 'At least one service must have billable === false');

        $this->assertSame(
            ['path' => 'Emitir', 'versaoSistema' => '2.0', 'billable' => true],
            $services['RELATORIOSITFIS92']
        );
    }
}
