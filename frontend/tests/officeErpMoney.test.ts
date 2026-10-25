import assert from 'node:assert/strict'
import test from 'node:test'

import { formatOfficeErpMoney, parseOfficeErpMoney } from '../shared/utils/officeErpCommercial.ts'

test('parses ungrouped Brazilian and canonical API amounts as exact cents', () => {
  assert.equal(parseOfficeErpMoney('100'), '100.00')
  assert.equal(parseOfficeErpMoney('100,5'), '100.50')
  assert.equal(parseOfficeErpMoney('100,00'), '100.00')
  assert.equal(parseOfficeErpMoney('100.00'), '100.00')
})

test('parses large valid Brazilian grouped amounts without numeric coercion', () => {
  assert.equal(parseOfficeErpMoney('9.007.199.254.740.993,01'), '9007199254740993.01')
})

test('rejects ambiguous groups, malformed amounts, negatives, excess precision and overflow', () => {
  for (const value of ['1.001', '1,001', '12.34,56', '1.00.000,00', '-1,00', '1000000000000000000,00']) {
    assert.throws(() => parseOfficeErpMoney(value), value)
  }
})

test('formats canonical cents with Brazilian grouping and exactly two decimal places', () => {
  assert.equal(formatOfficeErpMoney('9007199254740993.01'), 'R$ 9.007.199.254.740.993,01')
  assert.equal(formatOfficeErpMoney('100'), 'R$ 100,00')
  assert.throws(() => formatOfficeErpMoney('1.001'))
})
