<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * O registro de auditoria da prova de contrato do termo de autorização, e ele
 * é **acrescido por construção** — nunca alterado, nunca apagado.
 *
 * **Por que uma tabela própria, e não `support_access_logs`.** A spec exige
 * que o comando `serpro:record-term-proof` registre uma entrada de auditoria
 * com o digest que gravou, e não diz onde. As duas casas do repositório não
 * servem: `SupportAudit::logWrite()` exige um `Request` e um Membro
 * autenticado da conta corrente e faz nada sem os dois — um comando artisan
 * não tem nenhum dos dois, e um registro vazio parece cobertura e não é —; e
 * `support_access_logs` é a trilha de acesso de suporte, com `account_id` e
 * `ip` de uma pessoa operando numa conta alheia. Uma prova de contrato não é
 * acesso de suporte, não pertence a conta nenhuma e não vem de um IP. A
 * tabela é o que falta, e ela é do fato que guarda: sobre o provedor, e não
 * sobre nenhuma conta.
 *
 * **O que torna isto acrescentado, em três mecanismos nomeados.**
 *
 * 1. **A tabela não tem `updated_at`.** Não é um detalhe de estilo: um
 *    `update` que mudasse um valor deixaria a linha com um único carimbo
 *    dizendo que aquilo foi gravado naquele momento, e seria mentira. Sem a
 *    coluna, não há onde a mentira caberia.
 * 2. **O modelo recusa `save()` de linha existente e `delete()`.** Uma prova
 *    substituída não altera a anterior: ela vira uma linha nova, que carrega
 *    o digest e a hora que substituiu em `superseded_sha256` e
 *    `superseded_at`. É por isso que as duas colunas existem.
 * 3. **A prova gravada não sai daqui.** O digest desta tabela é uma impressão
 *    digital do **formato** do documento, e nada que a API publique ou que um
 *    Membro leia tem o que fazer com ele. Não há rota, não há resource e não
 *    há policy para este model.
 *
 * **O limite honesto do segundo mecanismo.** Ele age no model: um
 * `SerproTermProofRecord::query()->update(...)` escrito direto contra a
 * tabela passaria por baixo dele, como passaria por baixo de qualquer
 * verificação em PHP. O que o mecanismo compra é que o caminho normal — o
 * model, o `save()`, o `delete()` — não tem como alterar o registro, e o que
 * sobraria é escrita fora de banda, que é a mesma categoria de acidente que
 * a duplicata de `account_certificates` já nomeia na docblock da migration
 * dela.
 */
class SerproTermProofRecord extends Model
{
    /**
     * `updated_at` é `null` porque a coluna não existe, e não porque o model
     * decidiu ignorar um valor: o atributo que o Eloquent tentaria gravar em
     * toda atualização não tem para onde ir, e é essa incompatibilidade que
     * torna visível, no stack trace de qualquer tentativa, que a linha não
     * aceita ser atualizada.
     */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'superseded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Gravar a linha é sempre a primeira gravação; as seguintes recusam.
     *
     * `save()` num model novo é o que o comando usa, e ele passa. `save()`
     * numa linha que já existe — que é a única forma de o Eloquent fazer um
     * `UPDATE` — recusa com uma `LogicException` de mensagem fixa.
     *
     * A alternativa seria deixar o `save()` liberado e a disciplina vindo do
     * chamador, e isso é o oposto de uma garantia: o próximo autor de um
     * método aqui passaria por cima da regra e o registro viraria editável
     * sem que nada avise.
     *
     * @throws LogicException
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('O registro de prova de contrato é acrescentado, não alterado: grave outra linha.');
        }

        return parent::save($options);
    }

    /**
     * Apagar um registro de prova é perder a evidência de que alguém afirmou,
     * com um digest e uma hora, que o provedor aceita o documento. Por isso
     * não há caminho no model que faça isso.
     *
     * @throws LogicException
     */
    public function delete(): void
    {
        throw new LogicException('O registro de prova de contrato não pode ser apagado.');
    }
}
