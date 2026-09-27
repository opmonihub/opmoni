<?php

namespace Database\Factories;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Capture\FiscalXmlPath;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @extends Factory<FiscalDocument>
 */
class FiscalDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `storage_path`, `sha256` e `xml_bytes` não são nullable no schema, então
     * o estado padrão já devolve os três preenchidos — com o caminho derivado
     * por `FiscalXmlPath`, o mesmo contrato do writer de produção, mas sem
     * gravar arquivo nenhum. Quem precisa do XML de verdade no disco `fiscal`
     * usa `withStoredXml()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => Client::factory(),
            'source' => FiscalSource::NfeDistribuicao,
            'model' => FiscalModel::Nfe,
            'kind' => FiscalKind::Document,
            'chave_acesso' => self::chaveDeAcesso(),
            'event_id' => '',
            'nsu' => fake()->numberBetween(1, 999999),
            'emitente_cnpj' => fake()->numerify('#############'),
            // Nem toda linha da distribuição traz destinatário (nota de
            // entrada, resumo sem o XML completo), então o padrão é nulo.
            'destinatario_cnpj' => null,
            'valor_total' => fake()->randomFloat(2, 10, 50000),
            'emissao_at' => now()->subDays(fake()->numberBetween(1, 60)),
            'evento_ocorrido_em_at' => null,
            // O `schema` é preenchido pelo conector, não pela captura: o valor
            // exato (`nfe`, `nfeProc`, ...) depende do tipo de documento.
            'schema' => null,
            'storage_path' => '',
            'sha256' => hash('sha256', (string) Str::uuid()),
            'xml_bytes' => fake()->numberBetween(512, 65536),
            'mascarado' => false,
            'captured_at' => now(),
        ];
    }

    /**
     * Evento de um documento já captado: mesmo documento, mesmo NSU, terceira
     * linha da distribuição — a identidade é a chave composta
     * `(client_id, chave_acesso, event_id)`.
     */
    public function event(string $eventId = '110111'): static
    {
        return $this->state(fn (): array => [
            'kind' => FiscalKind::Event,
            'event_id' => $eventId,
            'evento_ocorrido_em_at' => now()->subHours(fake()->numberBetween(1, 72)),
        ]);
    }

    /**
     * Documento capturado de verdade: grava o XML no disco `fiscal` e alinha
     * `sha256` e `xml_bytes` aos bytes efetivamente gravados, para que
     * qualquer consumidor possa ler o arquivo e conferir as duas colunas sem
     * precisar saber que o disco involved é interno da factory.
     */
    public function withStoredXml(?string $xml = null): static
    {
        return $this->afterMaking(function (FiscalDocument $document) use ($xml): void {
            self::assertFiscalDiskIsFaked();

            $contents = $xml ?? self::nfeProc($document);

            Storage::disk('fiscal')->put($document->storage_path, $contents);

            $document->sha256 = hash('sha256', $contents);
            $document->xml_bytes = strlen($contents);
        });
    }

    public function configure(): static
    {
        return $this->afterMaking(function (FiscalDocument $document): void {
            if ($document->client instanceof Client) {
                $document->account_id = $document->client->account_id;
            }

            $document->storage_path = FiscalXmlPath::for(
                (int) $document->account_id,
                (int) $document->client_id,
                (string) $document->chave_acesso,
                (string) $document->event_id,
            );
        })->afterCreating(function (FiscalDocument $document): void {
            if ($document->client instanceof Client && $document->account_id !== $document->client->account_id) {
                $document->account_id = $document->client->account_id;
                $document->saveQuietly();
            }
        });
    }

    /**
     * O XML gravado tem cara de documento fiscal de terceiro — nome,
     * endereço, CPF/CNPJ. Um teste que esqueça `Storage::fake('fiscal')`
     * escreveria esse conteúdo no `storage/` de verdade da máquina de
     * desenvolvimento, e o teste continuaria verde. A factory não pode
     * reconfigurar o container para se proteger, então falha na hora.
     */
    private static function assertFiscalDiskIsFaked(): void
    {
        $normalise = fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/');

        $configured = $normalise((string) config('filesystems.disks.fiscal.root'));
        $resolved = $normalise(Storage::disk('fiscal')->path(''));

        if ($resolved === $configured) {
            throw new RuntimeException(
                'withStoredXml() exige Storage::fake(\'fiscal\') no setUp(): '
                .'sem ele o XML fiscal seria gravado em '.$configured.'.'
            );
        }
    }

    /**
     * Chave de acesso de 44 dígitos com o DV módulo 11 conferido, para que uma
     * linha criada pela factory passe pela mesma validação de identidade que
     * a captura real aplica.
     */
    private static function chaveDeAcesso(): string
    {
        $base = sprintf(
            '%02d%s%08d%08d%03d%d%d%07d%09d',
            fake()->numberBetween(11, 53),
            now()->format('ym'),
            (int) fake()->numerify('########'),
            0,
            fake()->numberBetween(1, 999),
            1,
            1,
            fake()->numberBetween(1, 9999999),
            0
        );

        return $base.self::digitoVerificador($base);
    }

    private static function digitoVerificador(string $base): int
    {
        $weight = 2;
        $sum = 0;

        for ($position = strlen($base) - 1; $position >= 0; $position--) {
            $sum += (int) $base[$position] * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $remainder = $sum % 11;

        return $remainder === 0 || $remainder === 1 ? 0 : 11 - $remainder;
    }

    /**
     * NF-e processada mínima, com a chave de acesso e o NSU do documento que a
     * factory está construindo, para que o arquivo gravado seja consistente
     * com a linha.
     */
    private static function nfeProc(FiscalDocument $document): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
          <NFe>
            <infNFe versao="4.00" Id="NFe{$document->chave_acesso}">
              <ide nNF="1" serie="1" mod="55">
                <cUF>35</cUF>
                <tpEmis>1</tpEmis>
                <dhEmi>{$document->emissao_at?->toIso8601String()}</dhEmi>
                <nNF>1</nNF>
              </ide>
              <emit><CNPJ>{$document->emitente_cnpj}</CNPJ></emit>
            </infNFe>
          </NFe>
        </nfeProc>
        XML;
    }
}
