<?php

namespace App\Console\Commands;

use App\Models\SerproConnection;
use App\Models\SerproTermProofRecord;
use App\Services\SerproTermSigner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Grava a prova de contrato que abre o gate de emissão do termo de
 * autorização, e o registro de auditoria que a acompanha.
 *
 * Este é o **único escritor sancionado** de `term_format_sha256` e
 * `term_format_proven_at`, e ele é a exceção declarada à regra da spec — que
 * é "nenhuma requisição, nenhum job e nenhuma agenda escreve essas colunas",
 * e não "nenhum código": um comando artisan é código de aplicação, e uma
 * regra mais larga obrigaria a exceptuar de dentro dela justamente a coisa
 * que a regra proíbe.
 *
 * **As cinco travas, e o que cada uma fecha.**
 *
 * 1. **O digest é medido, nunca digitado.** O comando não tem argumento de
 *    digest, e o que ele grava é o valor que `SerproTermSigner::formatDigest()`
 *    calcula naquele instante. Um operador que discorde do valor tem um
 *    defeito para corrigir, não uma flag para marcar: uma prova que registrasse
 *    a afirmação dele em vez da medida não provaria nada, e leria como
 *    evidência — que é pior do que não ter gate.
 * 2. **Há um autor, e ele é humano.** Um comando artisan não tem usuário
 *    autenticado, e sem o argumento `author` a autoria da prova seria o
 *    usuário do processo. A spec promete "evidência com autor", e este
 *    argumento é o que entrega isso.
 * 3. **Uma prova existente não é sobrescrita por engano.** `--replace` é
 *    obrigatório, e `--reason` é obrigatório **com** ele. Sem as duas, a
 *    segunda gravação é recusada — e é essa recusa que impede o defeito que a
 *    spec descreve: um digest diferente gravado depois reabrir o gate em
 *    silêncio, como se alguém tivesse ligado uma flag. A troca grava uma
 *    linha nova carregando o digest e a hora que substituiu; a linha antiga
 *    fica intacta.
 * 4. **Há confirmação.** Num terminal, o operador confirma. Fora de um
 *    terminal — o `docker compose exec`, o `artisan` de um deploy, o teste —
 *    não há a quem perguntar, e `--force` é o que declara que a pessoa sabe o
 *    que está fazendo.
 * 5. **A auditoria tem endereço, e ela é acrescentada.** A entrada vive em
 *    `serpro_term_proof_records`, que não tem `updated_at` e cujo model recusa
 *    `save()` de linha existente e `delete()`. `SupportAudit` não serve
 *    aqui: ele exige um `Request` e um Membro da conta e faz nada sem os
 *    dois, e um comando não tem nenhum dos dois — a entrada sairia vazia e
 *    pareceria cobertura.
 */
final class RecordSerproTermProof extends Command
{
    /**
     * `author` é argumento e não opção porque é obrigatório: uma prova sem
     * autor não é evidência, e um `--author` opcional transformaria a
     * exigência em algo que o console aceitaria esquecer.
     *
     * Não há argumento de digest, e a ausência é proposital — está escrito
     * na trava 1 da docblock.
     */
    protected $signature = 'serpro:record-term-proof
        {author : quem rodou o teste de contrato, para a auditoria}
        {--replace : substitui uma prova já gravada, e exige --reason}
        {--reason= : por que a prova anterior deixa de valer}
        {--force : confirma sem perguntar, para contexto não interativo}';

    protected $description = 'Grava a prova de contrato do formato do termo de autorização e o registro de auditoria dela';

    public function handle(): int
    {
        $conexao = SerproConnection::current();

        if ($conexao === null) {
            $this->error('Não há credencial de plataforma configurada para receber a prova.');

            return self::FAILURE;
        }

        $autor = trim((string) $this->argument('author'));
        $motivo = trim((string) $this->option('reason'));

        if ($autor === '') {
            $this->error('A prova precisa de um autor: diga quem rodou o teste de contrato.');

            return self::FAILURE;
        }

        $anterior = $conexao->term_format_proven_at === null ? null : [
            'term_format_sha256' => (string) $conexao->term_format_sha256,
            'term_format_proven_at' => $conexao->term_format_proven_at,
        ];

        if (! $this->autorizadoASubstituir($anterior, $motivo)) {
            return self::FAILURE;
        }

        $digest = SerproTermSigner::formatDigest();

        if (! $this->confirmado($digest)) {
            $this->error('Gravação cancelada.');

            return self::FAILURE;
        }

        // Uma transação, e a ordem importa: o registro de auditoria é
        // gravado **antes** das colunas do gate. Se a segunda escrita falhasse,
        // sobraria uma linha de auditoria sem prova — que é ruído. Na ordem
        // contrária, sobraria um gate aberto sem registro de quem o abriu, que
        // é o defeito que a auditoria existe para fechar.
        DB::transaction(function () use ($conexao, $digest, $autor, $motivo, $anterior): void {
            SerproTermProofRecord::query()->create([
                'term_format_sha256' => $digest,
                'recorded_by' => $autor,
                'reason' => $motivo === '' ? null : $motivo,
                'superseded_sha256' => $anterior['term_format_sha256'] ?? null,
                'superseded_at' => $anterior['term_format_proven_at'] ?? null,
            ]);

            $conexao->forceFill([
                'term_format_sha256' => $digest,
                'term_format_proven_at' => now(),
            ])->save();
        });

        $this->info("Prova de contrato gravada para o formato {$digest}.");
        $this->line('Emissão do termo de autorização liberada para o formato que este digest mede.');

        return self::SUCCESS;
    }

    /**
     * A trava 3: uma prova vigente não se substitui sem `--replace`, e
     * `--replace` sem `--reason` também não.
     *
     * As duas recusas dizem **o que fazer**, e não apenas que não deu. Um
     * "recusado" sem motivo deixaria o operador descobrir por conta própria
     * que existe uma segunda flag, e é essa descoberta que faz uma trava
     * virar convenção.
     *
     * @param  array{term_format_sha256: string, term_format_proven_at: Carbon}|null  $anterior
     */
    private function autorizadoASubstituir(?array $anterior, string $motivo): bool
    {
        if ($anterior === null) {
            return true;
        }

        if (! $this->option('replace')) {
            $this->error('Já existe uma prova de contrato gravada. Use --replace para substituí-la, com um --reason que diga por quê.');

            return false;
        }

        if ($motivo === '') {
            $this->error('Substituir uma prova exige o motivo: --reason diz o que mudou no formato ou no ambiente de teste.');

            return false;
        }

        return true;
    }

    /**
     * A trava 4: `--force` pula a pergunta, e sem ele a pergunta é feita
     * quando há terminal e recusada quando não há.
     *
     * **A ordem é `--force` primeiro, e isso não é detalhe.** O `--force` é
     * a declaração de que a pessoa sabe o que está fazendo, e quem passa por
     * ele não deve ser interrompido por uma pergunta que já respondeu. A
     * checagem de interatividade vem depois e cobre o resto: num `artisan` de
     * deploy ou num `docker compose exec` não há a quem perguntar, e
     * `$this->confirm()` ali devolveria o padrão **sem perguntar a ninguém** —
     * o que gravaria a prova com o `yes` de um padrão que ninguém escolheu.
     */
    private function confirmado(string $digest): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Sem terminal para confirmar: rode com --force para declarar que a prova foi verificada.');

            return false;
        }

        return $this->confirm("Confirmar que um teste de contrato real provou que o provedor aceita o formato {$digest}?", false);
    }
}
