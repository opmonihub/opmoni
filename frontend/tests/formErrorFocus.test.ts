import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

test('form validation focuses its first named invalid control and scopes server errors to the active form', async () => {
  const focused: string[] = []
  const control = (id: string) => ({ focus: () => focused.push(id), scrollIntoView() {} })
  const field = control('email'), fallback = control('active-form-field')
  const document = {
    getElementById(id: string) {
      if (id === 'email') return field
      if (id === 'active-form') return { querySelector: () => fallback }
      return null
    }
  }
  const { focusFormError } = loadAppComposable<{ focusFormError: (event?: { errors: { id: string }[] }, formId?: string) => Promise<void> }>('utils/formPresentation.ts', { document })
  await focusFormError({ errors: [{ id: 'email' }, { id: 'ignored' }] })
  await focusFormError(undefined, 'active-form')
  await focusFormError({ errors: [] }, 'closed-form')
  assert.deepEqual(focused, ['email', 'active-form-field'])
})

test('local validation focuses after the native form releases loadingAuto', async () => {
  let disabled = true
  let focused = 0
  const field = {
    focus() {
      if (!disabled) focused++
    },
    scrollIntoView() {}
  }
  const document = { getElementById: () => field }
  const { focusFormError } = loadAppComposable<{ focusFormError: (event: { errors: { id: string }[] }) => unknown }>('utils/formPresentation.ts', { document })
  const completion = focusFormError({ errors: [{ id: 'native-field' }] })
  // Nuxt UI emits the error before its submit wrapper finally releases loading.
  disabled = false
  await completion
  assert.equal(focused, 1)
})
