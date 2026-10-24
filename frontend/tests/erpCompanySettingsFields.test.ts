import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { erpSettingsBusinessFields, erpSettingsSystemFields, erpSettingsTaxFields } from '../app/utils/erpCompanySettingsFields.ts'

describe('erpCompanySettingsFields', () => {
  it('maps business tab keys from reference settings_business', () => {
    const keys = erpSettingsBusinessFields.map(f => f.key)
    assert.ok(keys.includes('cnpj'))
    assert.ok(keys.includes('razao_social'))
    assert.ok(keys.includes('ambiente'))
    assert.equal(keys.filter(k => k === 'tax_label_1').length, 0)
  })

  it('preserves numeric editing for existing opaque business references', () => {
    const references = erpSettingsBusinessFields.filter(field => ['currency_id', 'cidade_id'].includes(String(field.key)))
    assert.deepEqual(references.map(field => [field.key, field.type]), [['currency_id', 'number'], ['cidade_id', 'number']])
    assert.ok(references.every(field => field.options === undefined), 'unverified references must not acquire fabricated options')
  })

  it('maps tax tab keys from reference settings_tax', () => {
    const keys = erpSettingsTaxFields.map(f => f.key)
    assert.deepEqual(keys, ['tax_label_1', 'tax_number_1', 'tax_label_2', 'tax_number_2', 'enable_inline_tax'])
  })

  it('groups system settings and keeps fiscal codes and exact decimal rates as text', () => {
    const fields = Object.fromEntries(erpSettingsSystemFields.map(field => [field.key, field]))
    assert.deepEqual(
      ['cst_csosn_padrao', 'cst_pis_padrao', 'cst_cofins_padrao', 'cst_ipi_padrao', 'ncm_padrao'].map(key => [fields[key]?.type, fields[key]?.group]),
      Array(5).fill(['code', 'Classificação fiscal'])
    )
    assert.deepEqual(
      ['perc_icms_padrao', 'perc_pis_padrao', 'perc_cofins_padrao', 'perc_ipi_padrao', 'pCredSN'].map(key => [fields[key]?.type, fields[key]?.group]),
      Array(5).fill(['decimal', 'Alíquotas padrão'])
    )
    assert.ok(erpSettingsSystemFields.every(field => field.group))
  })
})
