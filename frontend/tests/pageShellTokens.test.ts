// tests/pageShellTokens.test.ts
//
// Guarda dos tokens de shell e tabela: a string do shell de scroll vive só em
// `pageShell.ts`, e as páginas da auditoria consomem os tokens extraídos em vez
// de re-digitá-los. Página `.vue` não é importável pelo runner: a guarda é a
// leitura de fonte, o precedente deste repositório para o que não tem superfície
// importável.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'

import { pageDetailClass, pageRecordScrollClass, pageScrollClass, pageTableClass } from '../app/utils/pageShell.ts'

function source(path: string): string {
  return readFileSync(new URL(path, import.meta.url), 'utf8')
}

describe('tokens de shell de página', () => {
  it('exporta os quatro tokens com a forma documentada', () => {
    assert.match(pageScrollClass, /overflow-y-auto/)
    assert.match(pageTableClass, /^relative /)
    assert.match(pageRecordScrollClass, /^h-full overflow-y-auto$/)
    assert.match(pageDetailClass, /max-w-6xl/)
  })

  it('não deixa cópia da string de scroll fora do módulo', () => {
    for (const page of [
      '../app/pages/work/modelos/[id].vue',
      '../app/pages/work/processos/[id].vue'
    ]) {
      assert.ok(!source(page).includes(pageScrollClass), `${page} re-digita pageScrollClass`)
    }
  })

  it('as fichas Work importam o token de scroll do módulo', () => {
    assert.match(source('../app/pages/work/modelos/[id].vue'), /:class="pageScrollClass"/)
    assert.match(source('../app/pages/work/processos/[id].vue'), /:class="pageScrollClass"/)
  })
})

describe('tokens de tabela', () => {
  it('Work › Modelos aplica workTableUi na lista', () => {
    const page = source('../app/pages/work/modelos.vue')
    assert.match(page, /import \{ workTableUi \} from '~\/utils\/workGroupedTable'/)
    assert.match(page, /:ui="workTableUi"/)
  })

  it('HomeSales aplica panelTableUi em vez de :ui inline', () => {
    const component = source('../app/components/home/HomeSales.vue')
    assert.match(component, /:ui="panelTableUi"/)
    assert.doesNotMatch(component, /border-separate border-spacing-0/)
  })
})

describe('contrato de erro fatal nas listas Admin', () => {
  it('cada lista de painel usa useRetryableLoad e ErrorRetryAlert', () => {
    for (const page of [
      '../app/pages/admin/index.vue',
      '../app/pages/admin/contas.vue',
      '../app/pages/admin/usuarios.vue',
      '../app/pages/admin/planos.vue',
      '../app/pages/admin/assinaturas.vue',
      '../app/pages/admin/suporte.vue'
    ]) {
      const src = source(page)
      assert.match(src, /useRetryableLoad\(/, `${page} sem useRetryableLoad`)
      assert.match(src, /<ErrorRetryAlert/, `${page} sem ErrorRetryAlert`)
    }
  })

  it('nenhuma lista Admin inventa UAlert de retry próprio', () => {
    // O UAlert legítimo aqui é o de `ErrorRetryAlert.vue`; nas páginas, um
    // `UAlert` no template é adorno de status (ex.: aviso de não configurado),
    // nunca superfície de retry.
    for (const page of [
      '../app/pages/admin/index.vue',
      '../app/pages/admin/contas.vue',
      '../app/pages/admin/usuarios.vue',
      '../app/pages/admin/planos.vue',
      '../app/pages/admin/assinaturas.vue',
      '../app/pages/admin/suporte.vue'
    ]) {
      const src = source(page)
      if (!src.includes('UAlert')) continue
      assert.doesNotMatch(src, /UAlert[^>]*@retry/, `${page} hand-rolla retry em UAlert`)
    }
  })
})
