// tests/fiscalFilters.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import type { FiscalDocumentRow, FiscalListFilters } from '../app/types/fiscal.ts'
import {
  availableFiscalModels,
  fiscalDocumentosPath,
  fiscalQuery,
  isFiscalModel,
  parseFiscalFilters
} from '../app/utils/fiscalFilters.ts'

/** Só o campo que a função dos modelos lê; o resto da linha não é dela. */
function row(model: FiscalDocumentRow['model']): FiscalDocumentRow {
  return { model } as FiscalDocumentRow
}

describe('a URL da tabela de documentos', () => {
  it('lê modelo repetido, cliente e página do jeito que a barra escreve', () => {
    const filters = parseFiscalFilters({ model: ['nfe', 'cte'], client_id: '2', page: '3' })

    assert.deepEqual(filters.model, ['nfe', 'cte'])
    assert.equal(filters.client_id, 2)
    assert.equal(filters.page, 3)
  })

  it('descarta modelo desconhecido e prende a página no primeiro valor', () => {
    const filters = parseFiscalFilters({ model: 'desconhecido', page: '0' })

    assert.equal(filters.model, undefined)
    assert.equal(filters.page, 1)
  })

  it('aceita a chave sem valor, que é o que o roteador devolve para `?model`', () => {
    // `?model` sozinho é `{ model: null }` no `route.query`, e não uma string
    // vazia: um leitor que só checasse `''` passaria esse null para a API.
    const filters = parseFiscalFilters({ model: null, page: null, issuer: null })

    assert.equal(filters.model, undefined)
    assert.equal(filters.page, 1)
    assert.equal(filters.issuer, undefined)
  })

  it('devolve a mesma tela depois de recarregar', () => {
    // O round-trip inteiro, e não o de um filtro só: um parser que resolve
    // `client_id` e perde `per_page` passa em qualquer aserção isolada e
    // quebra a barra de filtros no primeiro F5.
    const filters: FiscalListFilters = {
      model: ['nfe', 'cte'],
      client_id: 2,
      issuer: '123',
      recipient: '456',
      kind: 'event',
      issued_from: '2026-09-01',
      issued_to: '2026-09-30',
      amount_min: 10,
      amount_max: 100,
      sort: 'valor_total',
      direction: 'asc',
      page: 3,
      per_page: 50
    }

    assert.deepEqual(parseFiscalFilters(fiscalQuery(filters)), filters)
  })

  it('não escreve o que já é o padrão do backend', () => {
    // A URL vazia é a lista sem filtro, e ela precisa continuar sendo a mesma
    // depois de um clique em qualquer filtro.
    assert.deepEqual(fiscalQuery({}), {})
    assert.deepEqual(parseFiscalFilters({}), {
      page: 1,
      per_page: 25,
      sort: 'emissao_at',
      direction: 'desc'
    })
    assert.deepEqual(fiscalQuery({
      page: 1,
      per_page: 25,
      sort: 'emissao_at',
      direction: 'desc'
    }), {})
  })

  it('repete a chave do modelo em vez de juntar os valores', () => {
    assert.deepEqual(fiscalQuery({ model: ['nfe', 'cte'], page: 2 }), { model: ['nfe', 'cte'], page: '2' })
  })

  it('junta prefixo de CNPJ com o que o operador digitou e nunca envia vazio', () => {
    // `?issuer=` vazio volta do backend como lista sem filtro nenhum, debaixo
    // de uma barra que parece aplicada — o pior dos dois mundos.
    assert.equal(parseFiscalFilters({ issuer: '12.345', recipient: '' }).issuer, '12345')
    assert.equal(parseFiscalFilters({ issuer: '12.345', recipient: '' }).recipient, undefined)
    assert.deepEqual(fiscalQuery({ issuer: '12345', recipient: null }), { issuer: '12345' })
  })

  it('não transforma campo de valor vazio em filtro de zero', () => {
    // `Number('')` é 0: sem esta guarda, `?amount_min=` voltaria como
    // `amount_min=0` e a barra pareceria aplicada por um filtro que não muda
    // nada na consulta.
    assert.equal(parseFiscalFilters({ amount_min: '', amount_max: '   ' }).amount_min, undefined)
    assert.equal(parseFiscalFilters({ amount_min: '', amount_max: '   ' }).amount_max, undefined)
    assert.deepEqual(fiscalQuery({ amount_min: 0, amount_max: null }), { amount_min: '0' })
    assert.equal(parseFiscalFilters({ amount_min: '-1' }).amount_min, undefined)
  })

  it('ignora o que a API rejeitaria com 422', () => {
    const filters = parseFiscalFilters({
      model: ['nfe', 'nfe'],
      kind: 'resumo',
      client_id: 'dois',
      sort: 'chave_acesso',
      direction: 'lado',
      per_page: '30',
      issued_from: '01/09/2026'
    })

    assert.deepEqual(filters.model, ['nfe'])
    assert.equal(filters.kind, undefined)
    assert.equal(filters.client_id, undefined)
    assert.equal(filters.sort, 'emissao_at')
    assert.equal(filters.direction, 'desc')
    assert.equal(filters.per_page, 25)
    assert.equal(filters.issued_from, undefined)
  })

  it('monta o caminho da tabela para o link do painel', () => {
    assert.equal(fiscalDocumentosPath(), '/fiscal/documentos')
    assert.equal(fiscalDocumentosPath({}), '/fiscal/documentos')
    assert.equal(fiscalDocumentosPath({ model: ['nfe'] }), '/fiscal/documentos?model=nfe')
    assert.equal(
      fiscalDocumentosPath({ model: ['nfe', 'cte'], kind: 'event' }),
      '/fiscal/documentos?model=nfe&model=cte&kind=event'
    )
  })
})

describe('as opções de modelo do filtro', () => {
  it('lista cada modelo uma vez, na ordem em que a página os traz', () => {
    assert.deepEqual(availableFiscalModels([row('nfe'), row('cte'), row('cte')]), ['nfe', 'cte'])
  })

  it('não oferece modelo nenhum sem linha atrás', () => {
    assert.deepEqual(availableFiscalModels([]), [])
  })

  it('mantém o modelo selecionado disponível para o filtro sair', () => {
    // Filtro de modelo que esvaziou a tabela é justamente quando o operador
    // mais precisa do chip para tirá-lo.
    assert.deepEqual(availableFiscalModels([], ['nfse']), ['nfse'])
    assert.deepEqual(availableFiscalModels([row('cte')], ['cte', 'nfe']), ['cte', 'nfe'])
  })

  it('mantém o modelo selecionado depois de recarregar a tela', () => {
    assert.deepEqual(parseFiscalFilters({ model: 'nfse' }).model, ['nfse'])
    assert.deepEqual(fiscalQuery({ model: ['nfse'] }), { model: ['nfse'] })
    assert.deepEqual(parseFiscalFilters(fiscalQuery({ model: ['nfse'] })).model, ['nfse'])
  })

  it('recusa um modelo que a API rejeitaria, e a linha que o trouxe', () => {
    // Um modelo novo do backend num build antigo: ele aparece no volume do
    // painel, e nem aí pode virar chip ou `?model=` — a API responde 422 a um
    // valor fora da lista fechada.
    assert.equal(isFiscalModel('cte'), true)
    assert.equal(isFiscalModel('nfs-e'), false)
    assert.equal(isFiscalModel(3), false)

    const unknown = { model: 'nfs_e' } as unknown as FiscalDocumentRow
    assert.deepEqual(availableFiscalModels([row('nfe'), unknown]), ['nfe'])
  })
})
