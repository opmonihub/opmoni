<?php

namespace Tests\Unit;

use App\Services\Fiscal\Support\FiscalXmlEncoding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FiscalXmlEncodingTest extends TestCase
{
    public function test_converte_latin1_sem_alterar_bytes_originais(): void
    {
        $raw = '<?xml version="1.0" encoding="ISO-8859-1"?><raiz>Jo'.chr(0xE3).'o</raiz>';
        $view = (new FiscalXmlEncoding)->forDisplay($raw);

        $this->assertSame('João', strip_tags($view));
        $this->assertSame('UTF-8', (string) mb_detect_encoding($view, ['UTF-8'], true));
        $this->assertSame(0xE3, ord($raw[strpos($raw, 'Jo') + 2]));
    }

    public function test_mantem_utf8_sem_dupla_conversao(): void
    {
        $raw = '<?xml version="1.0" encoding="UTF-8"?><raiz>João</raiz>';

        $this->assertSame($raw, (new FiscalXmlEncoding)->forDisplay($raw));
    }

    /**
     * O `ã` em UTF-8 são dois bytes (`0xC3 0xA3`), e o ISO-8859-1 leria esse
     * par como `Ã£`. Converter um documento cujos bytes já são UTF-8
     * produziria esse mojibake, então a conversão é decidida pelo byte e não
     * pela etiqueta: aqui ela não acontece, e a prova é o par de bytes
     * intacto na saída.
     *
     * O rótulo, ao contrário, é reescrito em todos os caminhos — inclusive
     * neste. A saída é UTF-8 por contrato, e um documento que se declara
     * ISO-8859-1 depois de convertido seria um documento que mente sobre si
     * mesmo. Este teste fixa as duas metades do contrato de uma vez: corpo
     * intacto, rótulo coerente.
     */
    public function test_preserva_utf8_que_apenas_declara_iso_8859_1(): void
    {
        $raw = '<?xml version="1.0" encoding="ISO-8859-1"?><raiz>João</raiz>';

        $view = (new FiscalXmlEncoding)->forDisplay($raw);

        $this->assertSame('João', strip_tags($view));
        $this->assertSame(0xC3, ord($view[strpos($view, 'Jo') + 2]));
        $this->assertSame(0xA3, ord($view[strpos($view, 'Jo') + 3]));
        $this->assertSame('<?xml version="1.0" encoding="UTF-8"?><raiz>João</raiz>', $view);
    }

    public function test_aceita_declaracao_iso_8859_1_em_caixa_baixa(): void
    {
        $raw = '<?xml version="1.0" encoding="iso-8859-1"?><raiz>Jo'.chr(0xE3).'o</raiz>';

        $view = (new FiscalXmlEncoding)->forDisplay($raw);

        $this->assertSame('João', strip_tags($view));
        $this->assertStringContainsString('encoding="UTF-8"', $view);
    }

    public function test_aceita_declaracao_iso_8859_1_com_aspas_simples(): void
    {
        $raw = "<?xml version='1.0' encoding='ISO-8859-1'?><raiz>Jo".chr(0xE3).'o</raiz>';

        $view = (new FiscalXmlEncoding)->forDisplay($raw);

        $this->assertSame('João', strip_tags($view));
        $this->assertStringContainsString("encoding='UTF-8'", $view);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function declaracoesRecusadas(): array
    {
        return [
            ['<?xml version="1.0" encoding="windows-1252"?><raiz>Jo'.chr(0xE3).'o</raiz>'],
            ['<?xml version="1.0" encoding="UTF-16"?><raiz>Jo'.chr(0xE3).'o</raiz>'],
        ];
    }

    /**
     * `windows-1252` é a codificação que o raciocínio "toda NF-e que não é
     * UTF-8 é latin1" converteria sem perguntar — e a conversão troca cada
     * byte ímpar por `?`, um documento fiscal corrompido na tela sem nenhum
     * erro visível. Declaração que não autoriza a conversão é recusada, não
     * adivinhada. A exceção é comparada por igualdade, e não por conteúdo:
     * a mensagem é a frase fixa, sem o XML, porque ela atravessa log e toast.
     */
    #[DataProvider('declaracoesRecusadas')]
    public function test_recusa_declaracao_de_codificacao_invalida(string $raw): void
    {
        $this->expectExceptionObject(new RuntimeException('Codificação XML não suportada para prévia.'));

        (new FiscalXmlEncoding)->forDisplay($raw);
    }

    public function test_recusa_byte_invalido_sem_declaracao_de_codificacao(): void
    {
        $this->expectExceptionObject(new RuntimeException('Codificação XML não suportada para prévia.'));

        (new FiscalXmlEncoding)->forDisplay('<raiz>Jo'.chr(0xE3).'o</raiz>');
    }
}
