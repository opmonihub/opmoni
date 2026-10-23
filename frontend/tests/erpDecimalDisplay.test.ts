import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { formatErpDecimalDisplay } from '../shared/utils/erpDecimalDisplay.ts'

describe('ERP exact decimal display', () => {
  const cases: Array<[string | number | null | undefined, number, string]> = [
    ['12.5000', 2, '12,50'],
    [0, 2, '0,00'],
    ['0', 2, '0,00'],
    ['-10.1250', 0, '-10,125'],
    ['12345678901234567890.1234', 0, '12.345.678.901.234.567.890,1234'],
    [null, 0, '—'],
    [undefined, 0, '—'],
    ['', 0, '—'],
    ['não disponível', 0, 'não disponível'],
    ['1000', 0, '1.000'],
    ['12.0000', 0, '12'],
    ['12.1234567890123456789000', 2, '12,1234567890123456789'],
    ['0.00000001', 2, '0,00000001'],
    ['-0.0000', 2, '-0,00'],
    ['-1234567.8900', 4, '-1.234.567,8900'],
    ['12.5', 4, '12,5000'],
    [12.5, 0, '12,5'],
    ['12,50', 2, '12,50'],
    [' 12.5000 ', 2, ' 12.5000 '],
    ['01.20', 2, '01.20'],
    ['+12.50', 2, '+12.50'],
    ['1e3', 2, '1e3'],
    ['.5', 2, '.5'],
    ['12.', 2, '12.'],
    ['12.5000\n', 2, '12.5000\n'],
    [' ', 0, ' '],
    [Number.NaN, 0, 'NaN'],
    [Number.POSITIVE_INFINITY, 0, 'Infinity']
  ]

  for (const [value, minimumFractionDigits, expected] of cases) {
    it(`displays ${String(value)} with minimum ${minimumFractionDigits} as ${expected} without changing its input`, () => {
      const original = value
      assert.equal(formatErpDecimalDisplay(value, { minimumFractionDigits }), expected)
      assert.equal(value, original)
    })
  }

  it('defaults to no minimum fractional digits without rounding or adding currency', () => {
    assert.equal(formatErpDecimalDisplay('12.5000'), '12,5')
    assert.equal(formatErpDecimalDisplay('10.1250'), '10,125')
    assert.equal(formatErpDecimalDisplay('1000.0000'), '1.000')
  })
})
