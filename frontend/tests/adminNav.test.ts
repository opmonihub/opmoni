// tests/adminNav.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  adminNavbarTitle,
  adminPageActive,
  adminPages,
  adminSidebarChildren,
  adminTabs
} from '../app/utils/adminNav.ts'

describe('the office certificate entry', () => {
  it('is not in the global panel: it moved to Configurações', () => {
    // O e-CNPJ do escritório saiu do Painel Global e foi para
    // `/settings/certificado`: quem o entrega é o super_admin na Account
    // corrente, e a tela dele fica onde ficam as preferências da conta.
    assert.equal(adminPages.some(page => page.to === '/admin/certificado'), false)
    assert.equal(adminPages.some(page => page.label === 'Certificado do escritório'), false)
  })
})

describe('the Serpro screen', () => {
  const serpro = adminPages.find(page => page.to === '/admin/serpro')

  it('stays in the global panel, where the platform credential lives', () => {
    assert.ok(serpro, 'adminPages has no /admin/serpro entry')
    assert.equal(serpro.label, 'Serpro')
    assert.ok(serpro.icon, 'the entry carries no icon')
  })

  it('lights its own tab, and only its own', () => {
    assert.ok(serpro)
    const [group] = adminTabs('/admin/serpro')
    const active = group.filter(item => item.active)
    assert.equal(active.length, 1, `/admin/serpro lights ${active.map(i => i.label).join(', ') || 'nothing'}`)
    assert.equal(active[0]?.label, 'Serpro')
  })

  it('claims a sub-path of the screen without claiming the module index', () => {
    assert.ok(serpro)
    assert.equal(adminPageActive('/admin/serpro', serpro), true)
    assert.equal(adminPageActive('/admin', serpro), false)
    assert.equal(adminPageActive('/admin/serpro', adminPages[0]!), false)
  })

  it('names the screen in the navbar', () => {
    assert.equal(adminNavbarTitle('/admin/serpro'), 'Serpro')
  })

  it('marks the position in the sidebar', () => {
    const children = adminSidebarChildren('/admin/serpro')
    const active = children.filter(child => child.active)
    assert.equal(active.length, 1)
    assert.equal(active[0]?.label, 'Serpro')
  })
})
