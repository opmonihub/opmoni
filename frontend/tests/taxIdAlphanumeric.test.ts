import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { canLookupCnpj, canRegisterTypedCnpj, companyTaxIdEntry, formatTaxId, maskTaxId } from '../app/utils/taxId.ts'

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

  it('oferece o cadastro digitado para todo CNPJ completo, e não só para o alfanumérico', () => {
    // O backend aceita o CNPJ numérico que a fonte pública não conhece (o `404`
    // dele é cadastro digitado, não erro). Se a tela só oferecesse o caminho
    // digitado para o alfanumérico, esse documento seria cadastrável pela API e
    // impossível pela tela — as duas pontas discordando do mesmo contrato.
    assert.equal(canRegisterTypedCnpj('27.865.757/0001-02'), true)
    assert.equal(canRegisterTypedCnpj('27865757000102'), true)
    assert.equal(canRegisterTypedCnpj('12ABC345000188'), true)
    assert.equal(canRegisterTypedCnpj('12.ABC.345/0001-88'), true)

    // Documento incompleto não é documento: não há nada para cadastrar.
    assert.equal(canRegisterTypedCnpj(''), false)
    assert.equal(canRegisterTypedCnpj('12ABC3450001'), false)
    assert.equal(canRegisterTypedCnpj('529.982.247-25'), false)
  })

  it('não oferece nenhum caminho enquanto o documento não tem catorze caracteres', () => {
    assert.equal(companyTaxIdEntry(''), 'incomplete')
    assert.equal(companyTaxIdEntry('12ABC3450001'), 'incomplete')
    assert.equal(companyTaxIdEntry('12ABC3450001888'), 'incomplete')
    assert.equal(companyTaxIdEntry('12ABC3450001A'), 'incomplete')
    assert.equal(companyTaxIdEntry('529.982.247-25'), 'incomplete')
  })
})
