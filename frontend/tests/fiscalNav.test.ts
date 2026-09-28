// tests/fiscalNav.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { fiscalNav, fiscalSidebarChildren, fiscalTabs } from '../app/utils/fiscalNav.ts'

describe('fiscal sidebar navigation', () => {
  it('lists the module destinations in the order the rail shows them', () => {
    assert.deepEqual(fiscalNav.map(item => item.label), ['Painel', 'Documentos'])
    assert.deepEqual(fiscalNav.map(item => item.to), ['/fiscal', '/fiscal/documentos'])
  })

  it('marks only Painel active on the module panel', () => {
    assert.deepEqual(fiscalSidebarChildren('/fiscal').map(item => item.active), [true, false])
  })

  it('marks only Documentos active on the list', () => {
    // `/fiscal` é prefixo de `/fiscal/documentos`: casar por prefixo sozinho
    // acenderia Painel e Documentos ao mesmo tempo, e o rail mostraria duas
    // posições na tela que é justamente a do Documentos.
    assert.deepEqual(fiscalSidebarChildren('/fiscal/documentos').map(item => item.active), [false, true])
  })

  it('keeps Documentos active on any child route under the module', () => {
    // A regra é do prefixo, e vale para qualquer rota filha do módulo — não
    // para uma tela de detalhe que exista: o detalhe de um documento é uma
    // folha sobre a tabela, sem rota. O caminho abaixo é um caso da regra, e é
    // o que impede uma rota filha futura de abrir com o rail inteiro apagado.
    assert.deepEqual(fiscalSidebarChildren('/fiscal/documentos/123').map(item => item.active), [false, true])
    assert.deepEqual(fiscalSidebarChildren('/fiscal/documentos/123/eventos').map(item => item.active), [false, true])
  })

  it('marks the ancestor exact so the menu does not relight it by prefix', () => {
    const [painel, documentos] = fiscalSidebarChildren('/fiscal/documentos')
    assert.equal(painel?.exact, true)
    assert.equal(documentos?.exact, false)
  })

  it('carries the labels and routes the rail renders', () => {
    const children = fiscalSidebarChildren('/fiscal/documentos')
    assert.deepEqual(children.map(item => item.label), ['Painel', 'Documentos'])
    assert.deepEqual(children.map(item => item.to), ['/fiscal', '/fiscal/documentos'])
  })

  it('gives the shell tab bar the same answer as the rail', () => {
    // A barra e o rail são a mesma informação em dois lugares, e cada um
    // recalcula `active` do seu jeito quando renderiza. Divergir aqui deixaria
    // uma tela com duas posições acesas ao mesmo tempo.
    assert.deepEqual(fiscalTabs('/fiscal')[0]?.map(item => item.active), [true, false])
    assert.deepEqual(fiscalTabs('/fiscal/documentos')[0]?.map(item => item.active), [false, true])
    assert.deepEqual(fiscalTabs('/fiscal/documentos/123')[0]?.map(item => item.active), [false, true])
  })
})
