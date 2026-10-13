// tests/serproManualSearch.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  manualSearchModePresentation,
  manualSearchQuotaColor,
  manualSearchQuotaLabel,
  quotaExceededMessages
} from '../app/utils/serproManualSearch.ts'
import type { SerproManualSearchMode } from '../app/types/serpro.ts'

const modes: SerproManualSearchMode[] = ['full', 'slip_status']

describe('the manual search modes', () => {
  it('labels and explains both modes', () => {
    for (const mode of modes) {
      const entry = manualSearchModePresentation[mode]
      assert.ok(entry.label, `${mode} has no label`)
      assert.ok(entry.description.length > 10, `${mode} has no explanation`)
    }
  })

  it('says the partial mode is the paid/unpaid reading of the slip', () => {
    assert.match(manualSearchModePresentation.slip_status.label, /guia/i)
  })
})

describe('the quota column', () => {
  it('reads "X de Y" for a client with quota data', () => {
    assert.equal(manualSearchQuotaLabel(3, 10), '3 de 10')
    assert.equal(manualSearchQuotaLabel(0, 10), '0 de 10')
  })

  it('renders nothing — never a zero — when the quota did not arrive', () => {
    assert.equal(manualSearchQuotaLabel(null, 10), null)
    assert.equal(manualSearchQuotaLabel(undefined, 10), null)
    assert.equal(manualSearchQuotaLabel(3, null), null)
    // A limit of zero is no quota at all: the same em dash, not "0 de 0".
    assert.equal(manualSearchQuotaLabel(0, 0), null)
  })

  it('colours by how much of the month is left', () => {
    assert.equal(manualSearchQuotaColor(2, 10), 'primary')
    assert.equal(manualSearchQuotaColor(8, 10), 'warning', 'two queries left is the warning band')
    assert.equal(manualSearchQuotaColor(9, 10), 'warning')
    assert.equal(manualSearchQuotaColor(10, 10), 'error', 'the quota spent is an error, not a warning')
    assert.equal(manualSearchQuotaColor(12, 10), 'error')
  })
})

describe('the quota refusal (422)', () => {
  it('flattens one message per client under a single field', () => {
    const messages = quotaExceededMessages({
      data: {
        message: 'The given data was invalid.',
        errors: {
          client_ids: ['Cliente Alfa excede a cota mensal (restam 0 consultas).', 'Cliente Beta excede a cota mensal (restam 0 consultas).']
        }
      }
    })
    assert.deepEqual(messages, [
      'Cliente Alfa excede a cota mensal (restam 0 consultas).',
      'Cliente Beta excede a cota mensal (restam 0 consultas).'
    ])
  })

  it('reads indexed per-client fields as well', () => {
    const messages = quotaExceededMessages({
      data: {
        errors: {
          'client_ids.0': 'Cliente Alfa: cota esgotada.',
          'client_ids.2': 'Cliente Gama: cota esgotada.'
        }
      }
    })
    assert.deepEqual(messages, ['Cliente Alfa: cota esgotada.', 'Cliente Gama: cota esgotada.'])
  })

  it('falls back to the top-level message when no per-client refusal arrived', () => {
    assert.deepEqual(quotaExceededMessages({ data: { message: 'Cota esgotada para o mês corrente.' } }), [
      'Cota esgotada para o mês corrente.'
    ])
    assert.deepEqual(quotaExceededMessages({ message: 'Cota esgotada.' }), ['Cota esgotada.'])
  })

  it('answers nothing for a refusal with no readable message', () => {
    assert.deepEqual(quotaExceededMessages({}), [])
    assert.deepEqual(quotaExceededMessages(null), [])
  })
})
