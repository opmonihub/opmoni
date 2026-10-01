// tests/fiscalClients.test.ts
//
// A visão fiscal por cliente: rótulos, CSV e nomes de arquivo testados sem Vue
// e sem framework. O módulo importa runtime só de `fiscalPresentation.ts`
// (puro) e tipos com `import type`, com a extensão explícita, então o runner
// do Node o carrega por stripping nativo — o mesmo contrato de
// `fiscalPresentation.ts` e `fiscalFilters.ts`.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import type { FiscalClientSummary } from '../app/types/fiscal.ts'
import {
  fiscalClientCsv,
  fiscalClientCsvFileName,
  fiscalClientDocumentsPath,
  fiscalCompetenciaLabel,
  fiscalDirectionLabel,
  fiscalNumeroLabel
} from '../app/utils/fiscalClients.ts'

/** Um agregado como o `GET /fiscal/clients` entrega, com valor ausente num lado. */
function resumo(): FiscalClientSummary {
  return {
    client: { id: 7, name: 'Padaria Pão Quente Ltda', tax_id: '12345678000190' },
    total: 5,
    saidas: { qtd: 3, valor: '1234.50' },
    entradas: { qtd: 2, valor: null },
    por_modelo: { nfe: 4, cte: 1 },
    ultima_emissao_at: '2026-09-15T10:00:00Z',
    certificado_status: 'valid'
  }
}

describe('rótulo de direção', () => {
  it('nomeia saída e entrada em português', () => {
    assert.equal(fiscalDirectionLabel('saida'), 'Saída')
    assert.equal(fiscalDirectionLabel('entrada'), 'Entrada')
  })
})

describe('rótulo de competência', () => {
  it('escreve MM/AA a partir da chave YYYY-MM', () => {
    assert.equal(fiscalCompetenciaLabel('2026-09'), '09/26')
    assert.equal(fiscalCompetenciaLabel('2026-01'), '01/26')
  })

  it('diz que a competência não veio, em vez de inventar mês', () => {
    assert.equal(fiscalCompetenciaLabel(null), '—')
    assert.equal(fiscalCompetenciaLabel(undefined), '—')
    assert.equal(fiscalCompetenciaLabel(''), '—')
  })

  it('devolve a chave como veio quando ela não é um mês', () => {
    assert.equal(fiscalCompetenciaLabel('2026-13'), '2026-13')
    assert.equal(fiscalCompetenciaLabel('setembro'), 'setembro')
  })

  it('não monta a competência como data', () => {
    // A chave já é o mês, e montá-la como data jogaria o rótulo para o mês
    // anterior em qualquer fuso negativo — o mesmo defeito que
    // `fiscalMonthLabel` evita em `fiscalPresentation.ts`.
    const source = readFileSync(new URL('../app/utils/fiscalClients.ts', import.meta.url), 'utf8')
    assert.doesNotMatch(source, /new Date\(/)
  })
})

describe('rótulo do número da nota', () => {
  it('mostra número e série juntos', () => {
    assert.equal(fiscalNumeroLabel('000123', '1'), '000123/1')
  })

  it('mostra só o número quando a série não veio', () => {
    assert.equal(fiscalNumeroLabel('000123', null), '000123')
    assert.equal(fiscalNumeroLabel('000123', undefined), '000123')
  })

  it('diz que o número não veio, e nunca o inventa', () => {
    // O backfill do backend é `null` para o documento antigo: um zero ou um
    // texto vazio aqui seria um número de nota que o fisco nunca emitiu.
    assert.equal(fiscalNumeroLabel(null, '1'), '—')
    assert.equal(fiscalNumeroLabel(null, null), '—')
    assert.equal(fiscalNumeroLabel(undefined, undefined), '—')
  })
})

describe('link para os documentos do cliente', () => {
  it('filtra a tabela pelo cliente', () => {
    assert.equal(fiscalClientDocumentsPath(7), '/fiscal/documentos?client_id=7')
  })
})

describe('página de clientes', () => {
  it('usa pageTableClass, middleware auth e mostra ErrorRetryAlert com a frase do 404', () => {
    // A página é um `.vue` e não é importável: a guarda é a leitura de fonte,
    // o precedente deste repositório para o que não tem superfície importável.
    const page = readFileSync(new URL('../app/pages/fiscal/clientes.vue', import.meta.url), 'utf8')

    assert.match(page, /pageTableClass/)
    assert.match(page, /definePageMeta\(\{\s*middleware:\s*'auth'\s*\}\)/)
    assert.match(page, /ErrorRetryAlert/)
    assert.match(page, /Resumo por cliente ainda não disponível/)
    assert.match(page, /apiStatus\(error\.value\)\s*===\s*404/)
  })

  it('linka o menu da linha para a tabela filtrada e oferece o CSV do agregado', () => {
    const page = readFileSync(new URL('../app/pages/fiscal/clientes.vue', import.meta.url), 'utf8')

    assert.match(page, /fiscalClientDocumentsPath\(clientId\)/)
    assert.match(page, /navigateTo\(fiscalClientDocumentsPath\(clientId\)\)/)
    assert.match(page, /fiscalClientCsv\(summary\)/)
    assert.match(page, /fiscalClientCsvFileName\(summary\.client\.id,\s*summary\.ultima_emissao_at\)/)
  })
})

describe('nome do arquivo CSV', () => {
  it('leva o id do cliente e a competência da última emissão', () => {
    assert.equal(fiscalClientCsvFileName(7, '2026-09-15T10:00:00Z'), 'fiscal-cliente-7-2026-09.csv')
  })

  it('diz que não há emissão quando nunca houve, em vez de omitir o sufixo', () => {
    assert.equal(fiscalClientCsvFileName(7, null), 'fiscal-cliente-7-sem-emissao.csv')
    assert.equal(fiscalClientCsvFileName(7, undefined), 'fiscal-cliente-7-sem-emissao.csv')
  })
})

describe('CSV do cliente', () => {
  it('separa com ponto e vírgula e abre com cabeçalho em português', () => {
    const [header = '', line = ''] = fiscalClientCsv(resumo()).split('\r\n')

    assert.deepEqual(header.split(';'), [
      'Cliente',
      'CNPJ',
      'Total de documentos',
      'Saídas (qtd)',
      'Saídas (valor)',
      'Entradas (qtd)',
      'Entradas (valor)',
      'Por modelo',
      'Última emissão'
    ])
    assert.equal(line.split(';').length, 9)
  })

  it('formata valor em reais e marca o ausente com traço', () => {
    const csv = fiscalClientCsv(resumo())

    assert.match(csv, /R\$/)
    assert.match(csv, /1\.234,50/)
    // As entradas têm `valor: null`: zero seria uma soma que ninguém fez.
    assert.match(csv, /;—;/)
  })

  it('nomeia os modelos e data a última emissão', () => {
    const csv = fiscalClientCsv(resumo())

    assert.match(csv, /NF-e: 4/)
    assert.match(csv, /CT-e: 1/)
    assert.match(csv, /15\/09\/2026/)
  })

  it('escapa aspas dobrando e cerca o campo com ponto e vírgula', () => {
    const fixture = { ...resumo(), client: { ...resumo().client, name: 'Faria "Grãos"; Filhos' } }
    const [, line = ''] = fiscalClientCsv(fixture).split('\r\n')

    assert.match(line, /"Faria ""Grãos""; Filhos"/)
  })
})
