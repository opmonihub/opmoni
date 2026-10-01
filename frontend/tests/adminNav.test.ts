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
  const certificate = adminPages.find(page => page.to === '/admin/certificado')

  it('registers the screen in the global panel', () => {
    // The office e-CNPJ screen moved out of Monitoramento; the Painel Global is
    // where a super_admin uploads it against the current Account.
    assert.ok(certificate, 'adminPages has no /admin/certificado entry')
    assert.equal(certificate.label, 'Certificado do escritório')
    assert.ok(certificate.icon, 'the entry carries no icon')
  })

  it('lights its own tab, and only its own', () => {
    assert.ok(certificate)
    const [group] = adminTabs('/admin/certificado')
    const active = group.filter(item => item.active)
    assert.equal(active.length, 1, `/admin/certificado lights ${active.map(i => i.label).join(', ') || 'nothing'}`)
    assert.equal(active[0]?.label, 'Certificado do escritório')
  })

  it('claims a sub-path of the screen without claiming the module index', () => {
    assert.ok(certificate)
    assert.equal(adminPageActive('/admin/certificado', certificate), true)
    assert.equal(adminPageActive('/admin', certificate), false)
    assert.equal(adminPageActive('/admin/certificado', adminPages[0]!), false)
  })

  it('names the screen in the navbar', () => {
    assert.equal(adminNavbarTitle('/admin/certificado'), 'Certificado do escritório')
  })

  it('marks the position in the sidebar', () => {
    const children = adminSidebarChildren('/admin/certificado')
    const active = children.filter(child => child.active)
    assert.equal(active.length, 1)
    assert.equal(active[0]?.label, 'Certificado do escritório')
  })
})
