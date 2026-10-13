<?php

namespace Tests\Unit;

use App\Services\Fiscal\Support\DocZipDecoder;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class DocZipDecoderTest extends TestCase
{
    public function test_decodes_gzip_payload(): void
    {
        $xml = '<resNFe xmlns="http://www.portalfiscal.inf.br/nfe"><chNFe>1</chNFe></resNFe>';
        $payload = base64_encode(gzencode($xml));

        $this->assertSame($xml, (new DocZipDecoder)->decode($payload));
    }

    public function test_decodes_payload_with_surrounding_whitespace(): void
    {
        $xml = '<a/>';
        $payload = chunk_split(base64_encode(gzencode($xml)), 20, "\n");

        $this->assertSame($xml, (new DocZipDecoder)->decode($payload));
    }

    public function test_decodes_zlib_payload(): void
    {
        $xml = '<a/>';
        $payload = base64_encode(gzcompress($xml));

        $this->assertSame($xml, (new DocZipDecoder)->decode($payload));
    }

    public function test_decodes_zip_payload_tolerated_in_production(): void
    {
        $xml = '<a/>';
        $path = tempnam(sys_get_temp_dir(), 'dz');

        $this->assertNotFalse($path);

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('doc.xml', $xml);
        $zip->close();

        $payload = base64_encode((string) file_get_contents($path));
        unlink($path);

        $this->assertSame($xml, (new DocZipDecoder)->decode($payload));
    }

    public function test_throws_with_magic_when_payload_is_corrupt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/magic=/');

        (new DocZipDecoder)->decode(base64_encode('nao e um zip'));
    }
}
