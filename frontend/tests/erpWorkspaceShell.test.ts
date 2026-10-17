import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { describe, it } from 'node:test'

describe('ERP workspace shell', () => {
  it('erp layout uses WorkspaceContextMenu and company-aware navigation', async () => {
    const layout = await readFile(new URL('../app/layouts/erp.vue', import.meta.url), 'utf8')
    const nav = await readFile(new URL('../app/utils/erpModulesNav.ts', import.meta.url), 'utf8')
    assert.match(layout, /WorkspaceContextMenu/)
    assert.match(layout, /erpVisibleNavModules/)
    assert.match(layout, /currentCompany/)
    assert.match(nav, /\/erp\/configuracoes/)
  })

  it('WorkspaceContextMenu separates offices and companies', async () => {
    const menu = await readFile(new URL('../app/components/WorkspaceContextMenu.vue', import.meta.url), 'utf8')
    assert.match(menu, /Escritórios/)
    assert.match(menu, /Empresas/)
    assert.match(menu, /AccountMenu/)
    assert.match(menu, /CompanyMenu/)
  })

  it('company switch clears cached data like account switch', async () => {
    const auth = await readFile(new URL('../app/composables/useAuth.ts', import.meta.url), 'utf8')
    assert.match(auth, /switchCompany/)
    assert.match(auth, /clearAccountData\(\)/)
    assert.match(auth, /\/erp\/company\/switch/)
    assert.match(auth, /window\.location\.assign\('\/erp'\)/)
  })
})
