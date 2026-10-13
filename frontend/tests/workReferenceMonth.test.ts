import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  currentReferenceMonth,
  formatReferenceMonthLabel,
  parseReferenceMonth,
  shiftReferenceMonth
} from '../app/utils/workReferenceMonth.ts'
import { ref } from 'vue'
import { evaluateVueBindings, vueSource } from './helpers/compileVueComponent.ts'

describe('reference month', () => {
  it('generates templates for the selected Work month, falling back to the current month', (t) => {
    t.mock.timers.enable({ apis: ['Date'], now: new Date('2027-08-15T12:00:00Z').getTime() })
    const source = vueSource('pages/work/modelos/[id].vue')
    for (const selected of ['2027-02', undefined]) {
      const state = evaluateVueBindings(source, ['referenceMonth'], {
        ref,
        currentReferenceMonth,
        useState: (_key: string, initial: () => string) => ref(selected ?? initial())
      }) as { referenceMonth: ReturnType<typeof ref<string>> }
      assert.equal(state.referenceMonth.value, selected ?? '2027-08')
    }
  })
  it('accepts only real month numbers', () => {
    assert.equal(parseReferenceMonth('2026-01'), '2026-01')
    assert.equal(parseReferenceMonth('2026-12'), '2026-12')
    assert.equal(parseReferenceMonth('2026-00'), null)
    assert.equal(parseReferenceMonth('2026-13'), null)
  })

  it('keeps incomplete generation input out of the shared list month', () => {
    const shared = ref('2027-02')
    const state = evaluateVueBindings(vueSource('pages/work/modelos/[id].vue'), ['referenceMonth'], {
      ref,
      currentReferenceMonth,
      useState: () => shared
    }) as { referenceMonth: ReturnType<typeof ref<string>> }

    for (const draft of ['', '2027-', '2027-13', '2027-04']) {
      state.referenceMonth.value = draft
      assert.equal(state.referenceMonth.value, draft, 'Typing stays local to generation')
      assert.equal(shared.value, '2027-02', 'List requests retain the selected valid month')
    }
  })

  it('does not label or shift an invalid month', () => {
    assert.equal(formatReferenceMonthLabel('2026-13'), '2026-13')
    assert.equal(shiftReferenceMonth('2026-00', 1), '2026-00')
  })
})
