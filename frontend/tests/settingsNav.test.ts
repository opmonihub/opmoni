// tests/settingsNav.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { settingsPages, settingsSidebarChildren, settingsTabs } from '../app/utils/settingsNav.ts'

describe('the settings navigation', () => {
  it('offers the office certificate and the WhatsApp channel to account admins, not to other members', () => {
    const member = settingsPages(false)
    assert.equal(member.some(page => page.to === '/settings/certificado'), false)
    assert.equal(member.some(page => page.to === '/settings/whatsapp'), false)
    assert.deepEqual(member.map(page => page.to), ['/settings', '/settings/members', '/settings/notifications', '/settings/security'])

    const admin = settingsPages(true)
    const certificado = admin.find(page => page.to === '/settings/certificado')
    assert.ok(certificado, 'settingsPages(true) has no /settings/certificado entry')
    assert.equal(certificado.label, 'Certificado do escritório')
    assert.ok(certificado.icon, 'the entry carries no icon')

    const whatsapp = admin.find(page => page.to === '/settings/whatsapp')
    assert.ok(whatsapp, 'settingsPages(true) has no /settings/whatsapp entry')
    assert.equal(whatsapp.label, 'Canal WhatsApp')
    assert.ok(whatsapp.icon, 'the entry carries no icon')
    assert.equal(admin.length, 6)
  })

  it('keeps the same Portuguese office order for every projection', () => {
    assert.deepEqual(
      settingsPages(true).map(page => page.to),
      ['/settings', '/settings/members', '/settings/notifications', '/settings/security', '/settings/certificado', '/settings/whatsapp']
    )
    assert.deepEqual(settingsPages(false).map(page => page.label), ['Geral', 'Membros', 'Notificações', 'Segurança'])
    assert.deepEqual(settingsTabs(true)[0]?.map(page => page.label), settingsSidebarChildren('/settings', true).map(page => page.label))
  })

  it('builds one tab per page, with the index exact', () => {
    const [group] = settingsTabs(true)
    assert.equal(group.length, 6)
    assert.equal(group[0]?.exact, true)
    assert.equal(group[4]?.exact, false)
    assert.equal(group[5]?.exact, false)
  })

  it('builds the same list for the sidebar children', () => {
    const children = settingsSidebarChildren('/settings', true)
    assert.equal(children.length, 6)
    assert.equal(children[4]?.label, 'Certificado do escritório')
    assert.equal(children[5]?.label, 'Canal WhatsApp')
    assert.equal(children.every(child => child.icon == null), true)
  })

  it('marca só o filho da rota atual, com o índice exato', () => {
    const security = settingsSidebarChildren('/settings/security', true)
    assert.deepEqual(security.map(child => child.active), [false, false, false, true, false, false])

    const index = settingsSidebarChildren('/settings', false)
    assert.deepEqual(index.map(child => child.active), [true, false, false, false])
    assert.equal(index[0]?.active, true)
    assert.equal(settingsSidebarChildren('/settings/notifications', false)[0]?.active, false)
    assert.equal(settingsSidebarChildren('/settings/whatsapp', true)[5]?.active, true)
    assert.deepEqual(settingsSidebarChildren('/settings/members/42', false).filter(child => child.active).map(child => child.label), ['Membros'])
    assert.deepEqual(settingsSidebarChildren('/settings/notifications-extra', false).filter(child => child.active).map(child => child.label), [])
  })

  it('removes office-only links when the selected account loses its management context', () => {
    assert.deepEqual(settingsPages(true, true).map(page => page.to).slice(-3), [
      '/settings/certificado', '/settings/whatsapp', '/settings/integracoes-erp'
    ])
    assert.deepEqual(settingsPages(false, false).map(page => page.to), [
      '/settings', '/settings/members', '/settings/notifications', '/settings/security'
    ])
  })
})
