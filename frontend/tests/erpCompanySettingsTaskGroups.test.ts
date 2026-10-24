import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { erpSettingsBusinessFields, erpSettingsSystemFields, erpSettingsTaxFields } from '../app/utils/erpCompanySettingsFields.ts'

const businessGroups: Record<string, string[]> = {
  'Identificação da empresa': ['cnpj', 'ie', 'razao_social', 'name', 'start_date'],
  'Preferências operacionais': ['default_profit_percent', 'casas_decimais_valor', 'currency_id', 'currency_symbol_placement', 'time_zone', 'fy_start_month', 'accounting_method', 'transaction_edit_days', 'date_format', 'time_format'],
  'Endereço e contato': ['rua', 'numero', 'cidade_id', 'bairro', 'cep', 'telefone'],
  'Configuração fiscal': ['regime', 'inscricao_municipal', 'ambiente', 'csc_id', 'csc', 'aut_xml'],
  'Numeração fiscal': ['ultimo_numero_nfe', 'ultimo_numero_nfce', 'ultimo_numero_cte', 'ultimo_numero_mdfe', 'numero_serie_nfe', 'numero_serie_nfce', 'numero_serie_cte', 'numero_serie_mdfe']
}

const businessKeys = ['cnpj', 'ie', 'razao_social', 'name', 'start_date', 'default_profit_percent', 'casas_decimais_valor', 'currency_id', 'currency_symbol_placement', 'time_zone', 'fy_start_month', 'accounting_method', 'transaction_edit_days', 'date_format', 'time_format', 'rua', 'numero', 'cidade_id', 'bairro', 'cep', 'telefone', 'regime', 'ultimo_numero_nfe', 'ultimo_numero_nfce', 'ultimo_numero_cte', 'ultimo_numero_mdfe', 'inscricao_municipal', 'numero_serie_nfe', 'numero_serie_nfce', 'numero_serie_cte', 'numero_serie_mdfe', 'ambiente', 'csc_id', 'csc', 'aut_xml']
const taxKeys = ['tax_label_1', 'tax_number_1', 'tax_label_2', 'tax_number_2', 'enable_inline_tax']
const systemKeys = ['theme_color', 'enable_tooltip', 'exclusao_icms_pis_cofins', 'cst_csosn_padrao', 'cst_pis_padrao', 'cst_cofins_padrao', 'cst_ipi_padrao', 'ncm_padrao', 'perc_icms_padrao', 'perc_pis_padrao', 'perc_cofins_padrao', 'perc_ipi_padrao', 'pCredSN']

function groupedKeys(fields: typeof erpSettingsBusinessFields) {
  return Object.groupBy(fields, field => field.group ?? '')
}

describe('erp company settings task groups', () => {
  it('keeps every business settings key and assigns it once to its task', () => {
    const groups = groupedKeys(erpSettingsBusinessFields)
    assert.deepEqual(Object.keys(groups), Object.keys(businessGroups))
    for (const [group, expectedKeys] of Object.entries(businessGroups)) {
      assert.deepEqual(groups[group]?.map(field => field.key), expectedKeys, `keys for ${group}`)
    }

    const keys = erpSettingsBusinessFields.map(field => field.key)
    assert.equal(new Set(keys).size, keys.length, 'each business setting key appears once')
    assert.deepEqual(keys, businessKeys, 'grouping cannot drop, rename or reorder business field keys')
  })

  it('groups tax settings without changing their payload keys', () => {
    assert.deepEqual(erpSettingsTaxFields.map(field => field.key), taxKeys)
    assert.ok(erpSettingsTaxFields.every(field => field.group === 'Impostos personalizados'))
    assert.equal(new Set(erpSettingsTaxFields.map(field => field.key)).size, taxKeys.length)
  })

  it('preserves the existing system key order, groups and fiscal value types', () => {
    assert.deepEqual(erpSettingsSystemFields.map(field => field.key), systemKeys)
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

  it('keeps opaque legacy references numeric and without invented choices', () => {
    for (const field of erpSettingsBusinessFields.filter(field => ['currency_id', 'cidade_id'].includes(String(field.key)))) {
      assert.equal(field.type, 'number', `${String(field.key)} keeps its existing editor contract`)
      assert.equal(field.options, undefined, `${String(field.key)} has no unverified options`)
    }
  })
})
