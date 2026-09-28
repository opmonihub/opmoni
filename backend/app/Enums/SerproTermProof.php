<?php

namespace App\Enums;

/**
 * O que a prova de contrato gravada diz sobre o formato do termo que o
 * código monta hoje, e a resposta é sempre uma de três — e é por serem três
 * que o gate diz **qual** das duas metades do predicado faltou.
 *
 * **Por que um enum e não um booleano.** A spec escreve o predicado com duas
 * condições: `term_format_proven_at` não nulo **e** `term_format_sha256`
 * igual a `SerproTermSigner::formatDigest()`. Um `bool` repetiria essa lógica
 * em cada ponto que lê o gate, e o ponto que a lê pela metade é o
 * perigoso: `proven_at` preenchido com digest divergente é o estado que
 * acontece **de propósito** toda vez que o formato muda, e ele se parece com
 * o gate aberto para quem só pergunta "a prova foi gravada?". Os dois casos
 * recusados têm consertos opostos — um se grava a prova, o outro exige um
 * novo teste de contrato porque o documento mudou —, e um booleano não teria
 * como distinguir o que a pessoa deve fazer em cada um.
 *
 * **O que este enum não decide.** Ele lê duas colunas e compara uma string;
 * ele não sabe se o teste de contrato foi realmente feito, e não pode saber.
 * A afirmação de que o provedor aceita o documento é do operador que rodou o
 * comando, e a única defesa contra um operador errado é a auditoria
 * obrigatória da gravação — a linha em `serpro_term_proof_records` com o
 * digest, a hora e o nome de quem gravou.
 */
enum SerproTermProof: string
{
    /** Ninguém registrou prova: o gate está fechado e ninguém testou nada. */
    case Ausente = 'ausente';

    /**
     * Havia prova, mas ela foi gravada para outro formato.
     *
     * É o estado que o gate produz **sozinho** quando o documento ou uma das
     * quatro constantes de formato mudam depois de a prova ser gravada: o
     * digest novo não bate com o gravado, e a emissão recusa sem que ninguém
     * tenha decidido recusar. Chamar isto de "desatualizada" seria dizer o
     * mesmo com palavras de atualização, e a conotação é a oposta: a prova
     * não está velha, está **errada** para o documento de hoje.
     */
    case Divergente = 'divergente';

    /** As duas metades do predicado batem: a emissão pode seguir. */
    case Provado = 'provado';

    /**
     * O rótulo de cada estado para a tela e para a linha de `state_reason`.
     *
     * `Ausente` e `Divergente` são as duas mensagens que `issue()` recusa
     * com, e elas são diferentes **de propósito**: quem lê a recusa precisa
     * saber se o caminho é gravar a prova ou refazer o teste de contrato, e
     * uma frase única deixaria essa escolha com quem lê a tela.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ausente => 'A emissão do termo de autorização está bloqueada: nenhum teste de contrato provou que o provedor aceita este formato de documento.',
            self::Divergente => 'A emissão do termo de autorização está bloqueada: a prova de contrato gravada não corresponde ao formato atual do documento, que mudou depois que ela foi registrada.',
            self::Provedo => 'A prova de contrato cobre o formato atual do termo.',
        };
    }
}
