import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { canLookupCnpj, companyTaxIdEntry, formatTaxId, maskTaxId } from '../app/utils/taxId.ts'

describe('taxId com CNPJ alfanumérico', () => {
  it('formata os doze primeiros caracteres alfanuméricos e os dois dígitos', () => {
    assert.equal(formatTaxId('12ABC345000188'), '12.ABC.345/0001-88')
  })

  it('mantém a máscara do CPF numérico', () => {
    assert.equal(formatTaxId('52998224725'), '529.982.247-25')
    assert.equal(maskTaxId('52998224725'), '***.982.247-**')
  })

  it('só habilita a consulta pública para CNPJ numérico', () => {
    assert.equal(canLookupCnpj('12ABC345000188'), false)
    assert.equal(canLookupCnpj('27865757000102'), true)
    assert.equal(canLookupCnpj('27.865.757/0001-02'), true)
  })

  it('manda o CNPJ numérico para consulta e o alfanumérico para digitação', () => {
    assert.equal(companyTaxIdEntry('27.865.757/0001-02'), 'lookup')
    assert.equal(companyTaxIdEntry('27865757000102'), 'lookup')
    assert.equal(companyTaxIdEntry('12ABC345000188'), 'manual')
    assert.equal(companyTaxIdEntry('12.ABC.345/0001-88'), 'manual')
    assert.equal(companyTaxIdEntry('12abc345000188'), 'manual')
  })

  it('não oferece nenhum caminho enquanto o documento não tem catorze caracteres', () => {
    assert.equal(companyTaxIdEntry(''), 'incomplete')
    assert.equal(companyTaxIdEntry('12ABC3450001'), 'incomplete')
    assert.equal(companyTaxIdEntry('12ABC3450001888'), 'incomplete')
    assert.equal(companyTaxIdEntry('12ABC3450001A'), 'incomplete')
    assert.equal(companyTaxIdEntry('529.982.247-25'), 'incomplete')
  })
})
