// tests/settingsNav.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { settingsPages, settingsSidebarChildren, settingsTabs } from '../app/utils/settingsNav.ts'

describe('the settings navigation', () => {
  it('offers the office certificate and the WhatsApp channel to account admins, not to other members', () => {
    const member = settingsPages(false)
    assert.equal(member.some(page => page.to === '/settings/certificado'), false)
    assert.equal(member.some(page => page.to === '/settings/whatsapp'), false)
    assert.equal(member.length, 3)

    const admin = settingsPages(true)
    const certificado = admin.find(page => page.to === '/settings/certificado')
    assert.ok(certificado, 'settingsPages(true) has no /settings/certificado entry')
    assert.equal(certificado.label, 'Certificado do escritório')
    assert.ok(certificado.icon, 'the entry carries no icon')

    const whatsapp = admin.find(page => page.to === '/settings/whatsapp')
    assert.ok(whatsapp, 'settingsPages(true) has no /settings/whatsapp entry')
    assert.equal(whatsapp.label, 'Canal WhatsApp')
    assert.ok(whatsapp.icon, 'the entry carries no icon')
    assert.equal(admin.length, 5)
  })

  it('keeps the tab order: Geral, Notificações, Segurança, Certificado, Canal WhatsApp', () => {
    assert.deepEqual(
      settingsPages(true).map(page => page.to),
      ['/settings', '/settings/notifications', '/settings/security', '/settings/certificado', '/settings/whatsapp']
    )
  })

  it('builds one tab per page, with the index exact', () => {
    const [group] = settingsTabs(true)
    assert.equal(group.length, 5)
    assert.equal(group[0]?.exact, true)
    assert.equal(group[3]?.exact, false)
    assert.equal(group[4]?.exact, false)
  })

  it('builds the same list for the sidebar children', () => {
    const children = settingsSidebarChildren('/settings', true)
    assert.equal(children.length, 5)
    assert.equal(children[3]?.label, 'Certificado do escritório')
    assert.equal(children[4]?.label, 'Canal WhatsApp')
    assert.equal(children.every(child => child.icon == null), true)
  })

  it('marca só o filho da rota atual, com o índice exato', () => {
    const security = settingsSidebarChildren('/settings/security', true)
    assert.deepEqual(security.map(child => child.active), [false, false, true, false, false])

    const index = settingsSidebarChildren('/settings', false)
    assert.deepEqual(index.map(child => child.active), [true, false, false])
    assert.equal(index[0]?.active, true)
    assert.equal(settingsSidebarChildren('/settings/notifications', false)[0]?.active, false)
    assert.equal(settingsSidebarChildren('/settings/whatsapp', true)[4]?.active, true)
  })
})
