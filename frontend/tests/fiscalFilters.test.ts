// tests/fiscalFilters.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import type { FiscalListFilters } from '../app/types/fiscal.ts'
import {
  appliedFiscalFilters,
  availableFiscalModels,
  fiscalDocumentosPath,
  fiscalQuery,
  isFiscalModel,
  parseFiscalFilters
} from '../app/utils/fiscalFilters.ts'

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
      q: 'Nota 123',
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
    // A busca entra na URL como qualquer filtro, com os caracteres
    // especiais codificados — inclusive o `%`, que aqui é texto e não
    // codificação.
    assert.equal(fiscalDocumentosPath({ q: 'Nota 123' }), '/fiscal/documentos?q=Nota%20123')
    assert.equal(fiscalDocumentosPath({ q: 'Empresa 50%' }), '/fiscal/documentos?q=Empresa%2050%25')
  })

  describe('a busca por texto', () => {
    it('lê o valor com trim e descarta o vazio ou só de espaços', () => {
      assert.equal(parseFiscalFilters({ q: '  Nota 123  ' }).q, 'Nota 123')
      assert.equal(parseFiscalFilters({ q: '' }).q, undefined)
      assert.equal(parseFiscalFilters({ q: '   ' }).q, undefined)
      assert.equal(parseFiscalFilters({ q: null }).q, undefined)
    })

    it('só escreve na URL quando a busca tem texto', () => {
      assert.deepEqual(fiscalQuery({ q: null }), {})
      assert.deepEqual(fiscalQuery({ q: '' }), {})
      assert.deepEqual(fiscalQuery({ q: 'Nota 123' }), { q: 'Nota 123' })
    })
  })
})

describe('a confirmação do rascunho dos filtros', () => {
  const atual: FiscalListFilters = { page: 1, per_page: 25, sort: 'emissao_at', direction: 'desc' }

  it('não escreve na URL um valor que a consulta não aceitaria', () => {
    // Estes cinco são os que quebravam: o `-5` e a vírgula iam para a URL como
    // `-5` e `NaN`, a leitura seguinte descartava os três, e a barra ficava
    // parecendo aplicada sobre uma consulta sem filtro.
    const next = appliedFiscalFilters(atual, {
      amount_min: '-5',
      amount_max: '1,50',
      issued_from: '01/09/2026'
    })

    assert.equal(next.amount_min, undefined)
    assert.equal(next.amount_max, undefined)
    assert.equal(next.issued_from, undefined)
    assert.equal(fiscalQuery(next).amount_min, undefined)
    assert.equal(fiscalQuery(next).amount_max, undefined)
  })

  it('limpa o filtro quando o campo volta vazio', () => {
    const current: FiscalListFilters = { ...atual, issuer: '123', amount_min: 10 }
    const next = appliedFiscalFilters(current, { issuer: '', amount_min: '' })

    assert.equal(next.issuer, undefined)
    assert.equal(next.amount_min, undefined)
    assert.deepEqual(fiscalQuery({ ...next, page: 1 }), {})
  })

  it('normaliza o CNPJ digitado e corta o que passa do tamanho', () => {
    // `12.3` no campo é `123` como filtro; o que passa de 14 dígitos é o
    // suficiente para casar qualquer CNPJ e assim não filtra nada.
    const next = appliedFiscalFilters(atual, { issuer: '12.345', recipient: '12345678901234567890' })

    assert.equal(next.issuer, '12345')
    assert.equal(next.recipient, '12345678901234')
    assert.deepEqual(fiscalQuery(next), { issuer: '12345', recipient: '12345678901234' })
  })

  it('mantém o que o rascunho não toca e o que ele confirma é o que a URL filtra', () => {
    const current: FiscalListFilters = { ...atual, model: ['nfe'], client_id: 7, page: 4 }
    const next = appliedFiscalFilters(current, { issuer: '12345', amount_min: '10' })

    assert.deepEqual(next.model, ['nfe'])
    assert.equal(next.client_id, 7)
    assert.deepEqual(fiscalQuery({ ...next, page: 1 }), {
      model: ['nfe'],
      client_id: '7',
      issuer: '12345',
      amount_min: '10'
    })
  })

  it('substitui a busca e a limpa com o campo vazio', () => {
    const current: FiscalListFilters = { ...atual, q: 'nota antiga' }
    const next = appliedFiscalFilters(current, { q: 'Nota 123' })

    assert.equal(next.q, 'Nota 123')

    const limpa = appliedFiscalFilters(next, { q: '' })

    assert.equal(limpa.q, undefined)
    assert.deepEqual(fiscalQuery({ ...limpa, page: 1 }), {})
  })
})

describe('as opções de modelo do filtro', () => {
  it('lista cada modelo uma vez, na ordem em que a consulta devolveu', () => {
    assert.deepEqual(availableFiscalModels(['nfe', 'cte', 'cte']), ['nfe', 'cte'])
  })

  it('não oferece modelo nenhum sem resultado', () => {
    assert.deepEqual(availableFiscalModels([]), [])
  })

  it('mantém o modelo selecionado disponível para o filtro sair', () => {
    // Filtro de modelo que esvaziou a tabela é justamente quando o operador
    // mais precisa do chip para tirá-lo.
    assert.deepEqual(availableFiscalModels([], ['nfse']), ['nfse'])
    assert.deepEqual(availableFiscalModels(['cte'], ['cte', 'nfe']), ['cte', 'nfe'])
  })

  it('oferece um modelo que não tem linha nenhuma na página atual', () => {
    // A lista que entra aqui é a da consulta (`available_models`), e ela
    // descreve o resultado inteiro. Na página 3 de uma lista de NF-e não há
    // linha de CT-e, e o chip de CT-e precisa continuar ali: sem ele, um filtro
    // legal deixa de ser oferecível e o que resta é o estado da paginação
    // fingindo ser filtro.
    assert.deepEqual(availableFiscalModels(['cte']), ['cte'])
    assert.deepEqual(availableFiscalModels(['nfe', 'cte']), ['nfe', 'cte'])
  })

  it('mantém o modelo selecionado depois de recarregar a tela', () => {
    assert.deepEqual(parseFiscalFilters({ model: 'nfse' }).model, ['nfse'])
    assert.deepEqual(fiscalQuery({ model: ['nfse'] }), { model: ['nfse'] })
    assert.deepEqual(parseFiscalFilters(fiscalQuery({ model: ['nfse'] })).model, ['nfse'])
  })

  it('recusa um modelo que a API rejeitaria, antes de oferecer o chip', () => {
    // Um modelo novo do backend num build antigo: ele aparece no volume do
    // painel e em `available_models`, e nem aí pode virar chip ou `?model=` — a
    // API responde 422 a um valor fora da lista fechada. A guarda é a mesma que
    // a leitura da query faz, e ela entra antes da lista de opções.
    assert.equal(isFiscalModel('cte'), true)
    assert.equal(isFiscalModel('nfs-e'), false)
    assert.equal(isFiscalModel(3), false)

    const daApi = ['nfe', 'cte', 'nfs_e']
    assert.deepEqual(availableFiscalModels(daApi.filter(isFiscalModel)), ['nfe', 'cte'])
  })

  it('filtra o CT-e OS e o GTV-e, que o backend grava e esta lista tinha de saber', () => {
    // `cte_os` e `gtve` são modelos que o enum do backend tem e esta lista
    // conhecia: o documento era capturado, gravado, listado e não filtrável, e
    // nenhuma das três primeiras coisas denuncia a quarta. A ida e a volta pela
    // query é o contrato — um chip que some no F5 é um filtro que não existe.
    assert.equal(isFiscalModel('cte_os'), true)
    assert.equal(isFiscalModel('gtve'), true)

    assert.deepEqual(parseFiscalFilters({ model: 'cte_os' }).model, ['cte_os'])
    assert.deepEqual(parseFiscalFilters({ model: 'gtve' }).model, ['gtve'])

    // A lista inteira do enum, para que a próxima família nova não repita isto.
    assert.deepEqual(
      availableFiscalModels(['nfe', 'nfce', 'cte', 'cte_os', 'gtve', 'nfse'].filter(isFiscalModel)),
      ['nfe', 'nfce', 'cte', 'cte_os', 'gtve', 'nfse']
    )
  })
})
