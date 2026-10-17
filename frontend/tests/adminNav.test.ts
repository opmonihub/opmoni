// tests/adminNav.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  adminNavbarTitle,
  adminPageActive,
  adminPages,
  adminSidebarChildren,
  adminSidebarItem,
  adminTabs
} from '../app/utils/adminNav.ts'

describe('the collapsible Admin menu', () => {
  it('groups all admin routes under one native trigger', () => {
    const item = adminSidebarItem('/')
    assert.equal(item.label, 'Admin')
    assert.equal(item.type, 'trigger')
    assert.equal(item.value, 'admin')
    assert.equal(item.to, '/admin')
    assert.ok(item.icon)
    assert.deepEqual(item.children?.map(child => child.to), adminPages.map(page => page.to))
    assert.equal(item.children?.length, 9)
  })

  it('projects text-only children while keeping the Admin root icon', () => {
    for (const path of ['/', '/admin', '/admin/serpro', '/admin/usuarios/42']) {
      const item = adminSidebarItem(path)
      assert.equal(item.icon, 'i-lucide-shield', path)
      assert.deepEqual(item.children, adminSidebarChildren(path), path)
      for (const child of item.children!) {
        assert.equal(child.icon, undefined, `${path}: ${child.label}`)
        assert.equal('icon' in child, false, `${path}: ${child.label}`)
      }
    }
  })

  it('preserves shared page metadata icons on Admin tabs after projecting sidebar children', () => {
    const expectedIcons = [
      'i-lucide-layout-dashboard',
      'i-lucide-building-2',
      'i-lucide-layers',
      'i-lucide-receipt',
      'i-lucide-box',
      'i-lucide-badge-check',
      'i-lucide-users',
      'i-lucide-life-buoy',
      'i-lucide-plug'
    ]
    for (const path of ['/', '/admin', '/admin/serpro', '/admin/usuarios/42']) {
      adminSidebarChildren(path)
      assert.deepEqual(adminPages.map(page => page.icon), expectedIcons, path)
      assert.deepEqual(adminTabs(path).flat().map(tab => ({ label: tab.label, icon: tab.icon, to: tab.to })), adminPages, path)
    }
  })

  it('starts closed outside administrative routes', () => {
    for (const path of ['/', '/settings', '/administrator', '/admin-tools']) {
      const item = adminSidebarItem(path)
      assert.equal(item.defaultOpen, false, path)
      assert.equal(item.active, false, path)
    }
  })

  it('opens and marks its parent on the admin index and nested routes', () => {
    for (const path of ['/admin', '/admin/contas', '/admin/usuarios/42']) {
      const item = adminSidebarItem(path)
      assert.equal(item.defaultOpen, true, path)
      assert.equal(item.active, true, path)
    }
  })

  it('highlights the selected child without highlighting Resumo on a nested route', () => {
    const item = adminSidebarItem('/admin/serpro')
    assert.equal(item.children?.[0]?.exact, true)
    assert.deepEqual(item.children?.filter(child => child.active).map(child => child.label), ['Serpro'])
  })
})

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
