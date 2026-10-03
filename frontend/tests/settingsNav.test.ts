// tests/settingsNav.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { settingsPages, settingsSidebarChildren, settingsTabs } from '../app/utils/settingsNav.ts'

describe('the settings navigation', () => {
  it('offers the office certificate to account admins, not to other members', () => {
    const member = settingsPages(false)
    assert.equal(member.some(page => page.to === '/settings/certificado'), false)
    assert.equal(member.length, 3)

    const admin = settingsPages(true)
    const certificado = admin.find(page => page.to === '/settings/certificado')
    assert.ok(certificado, 'settingsPages(true) has no /settings/certificado entry')
    assert.equal(certificado.label, 'Certificado do escritório')
    assert.ok(certificado.icon, 'the entry carries no icon')
    assert.equal(admin.length, 4)
  })

  it('keeps the tab order: Geral, Notificações, Segurança, Certificado', () => {
    assert.deepEqual(
      settingsPages(true).map(page => page.to),
      ['/settings', '/settings/notifications', '/settings/security', '/settings/certificado']
    )
  })

  it('builds one tab per page, with the index exact', () => {
    const [group] = settingsTabs(true)
    assert.equal(group.length, 4)
    assert.equal(group[0]?.exact, true)
    assert.equal(group[3]?.exact, false)
  })

  it('builds the same list for the sidebar children', () => {
    const children = settingsSidebarChildren(true)
    assert.equal(children.length, 4)
    assert.equal(children[3]?.label, 'Certificado do escritório')
    assert.equal(children.every(child => child.icon == null), true)
  })
})
