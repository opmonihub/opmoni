import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { erpSettingsBusinessFields, erpSettingsTaxFields } from '../app/utils/erpCompanySettingsFields.ts'

describe('erpCompanySettingsFields', () => {
  it('maps business tab keys from reference settings_business', () => {
    const keys = erpSettingsBusinessFields.map(f => f.key)
    assert.ok(keys.includes('cnpj'))
    assert.ok(keys.includes('razao_social'))
    assert.ok(keys.includes('ambiente'))
    assert.equal(keys.filter(k => k === 'tax_label_1').length, 0)
  })

  it('maps tax tab keys from reference settings_tax', () => {
    const keys = erpSettingsTaxFields.map(f => f.key)
    assert.deepEqual(keys, ['tax_label_1', 'tax_number_1', 'tax_label_2', 'tax_number_2', 'enable_inline_tax'])
  })
})
