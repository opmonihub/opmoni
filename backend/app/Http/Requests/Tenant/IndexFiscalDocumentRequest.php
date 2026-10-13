<?php

namespace App\Http\Requests\Tenant;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Read\FiscalDocuments;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Os filtros da tabela de documentos capturados.
 *
 * Filtro inválido é 422 com a chave que está errada, e nunca uma lista vazia:
 * quem recebe vazio acredita que a consulta rodou e não achou nada, que é uma
 * resposta diferente de "a lista está vazia". Por isso os enums são fechados
 * aqui, e não tolerados para o banco decidir o que fazer com o valor.
 */
class IndexFiscalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ler o que a conta capturou é leitura de carteira, e carteira é
        // visível para qualquer membro (`FiscalDocumentPolicy::viewAny`). O
        // gate mora no Request porque é ele que dá entrada na ação — o
        // controller não repete a autorização para não ter dois lugares onde
        // ela pode faltar.
        return Gate::allows('viewAny', FiscalDocument::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'model' => ['sometimes', 'array', 'max:20'],
            'model.*' => ['required', Rule::in(array_column(FiscalModel::cases(), 'value'))],
            'kind' => ['sometimes', Rule::in(array_column(FiscalKind::cases(), 'value'))],
            'client_id' => ['sometimes', 'integer', $this->clienteDaConta()],
            // A busca por texto é entrada livre do operador: número da nota,
            // chave de acesso ou nome do cliente. O valor em si é o formato —
            // igualdade, igualdade de 44 dígitos e `LIKE` são decisões do
            // serviço —, e aqui mora só o teto: nome de cliente é o texto mais
            // longo plausível, e acima dele a resposta é 422 nomeando `q`, e
            // nunca uma lista vazia que pareça resultado.
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            // O CNPJ entra por prefixo, porque é o que o operador digita
            // quando lembra o começo dele e não o número inteiro. Só dígitos,
            // e no máximo o tamanho do CNPJ: é o que impede o `%` de virar
            // curinga na consulta.
            'issuer' => ['sometimes', 'nullable', 'digits_between:1,14'],
            'recipient' => ['sometimes', 'nullable', 'digits_between:1,14'],
            'issued_from' => ['sometimes', 'date_format:Y-m-d'],
            'issued_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:issued_from'],
            'amount_min' => ['sometimes', 'numeric', 'min:0'],
            'amount_max' => ['sometimes', 'numeric', $this->tetoDoValor()],
            // A lista de campos ordenáveis e a de tamanhos de página moram no
            // serviço, que é quem os aplica. Validar contra uma cópia aqui
            // deixaria duas listas para divergir: um campo aceito e não
            // ordenado cai no padrão calado, e um tamanho aceito e não aplicado
            // pagina em 25 sem o Request ter visto nada.
            'sort' => ['sometimes', Rule::in(FiscalDocuments::ORDENS)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in(FiscalDocuments::PAGINAS)],
        ];
    }

    /**
     * O cliente tem que ser da conta corrente — e pode estar removido.
     *
     * `Rule::exists` consulta a tabela direto, sem escopo global e sem o
     * `SoftDeletingScope` do modelo, então ele enxerga cliente removido. Isso é
     * o que a lista precisa: o documento guardado é histórico legítimo e tem
     * de continuar alcançável pelo id de quem o emitiu, e `withoutTrashed()`
     * aqui transformaria um filtro legítimo em 422. `IndexClientRequest` usa
     * `withoutTrashed()` porque lista a carteira — os dois.Requests cuidam de
     * coisas diferentes, e a regra do `exists` é a mesma.
     *
     * O `account_id` é o que segura a outra ponta: cliente de outra conta não
     * passa, e a resposta é 422 em vez de lista vazia — lista vazia
     * confirmaria que o id existe.
     */
    private function clienteDaConta(): Exists
    {
        return Rule::exists('clients', 'id')
            ->where('account_id', resolve(CurrentTenant::class)->accountId);
    }

    /**
     * O teto só pode ser menor que a base quando a base foi enviada.
     *
     * `gte:amount_min` compara contra o valor do campo irmão, e um campo
     * ausente é um valor que a comparação não sabe ler: com a regra literal,
     * `?amount_max=100` — quem só quer até um valor e não diz o piso — levaria
     * 422. Sem a base, o piso é zero, que é o mesmo limite que `amount_min`
     * impõe do outro lado. O `after_or_equal:issued_from` do `issued_to` não
     * precisa disso: a data ausente é comparada como ausência, e data
     * invertida é sempre erro.
     */
    private function tetoDoValor(): string
    {
        return 'gte:'.($this->filled('amount_min') ? 'amount_min' : '0');
    }
}
