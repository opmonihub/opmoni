import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { deadlineBadgePresentation } from '../app/utils/portfolioLabels.ts'

describe('deadline badge presentation', () => {
  it('keeps the missing state neutral and without a date', () => {
    assert.deepEqual(deadlineBadgePresentation('missing'), {
      color: 'neutral',
      icon: 'i-lucide-circle-minus',
      label: 'Sem cadastro',
      title: undefined
    })
  })

  it('shows the date and status context for an expired document', () => {
    assert.deepEqual(deadlineBadgePresentation('expired', '12/08/2026'), {
      color: 'error',
      icon: 'i-lucide-circle-alert',
      label: '12/08/2026',
      title: 'Vencido'
    })
  })

  it('uses the warning treatment for an expiring document without a date', () => {
    assert.deepEqual(deadlineBadgePresentation('expiring'), {
      color: 'warning',
      icon: 'i-lucide-clock-alert',
      label: 'A vencer',
      title: undefined
    })
  })
})
