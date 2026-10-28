import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

test('form validation focuses its first named invalid control and scopes server errors to the active form', () => {
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
  const { focusFormError } = loadAppComposable<{ focusFormError: (event?: { errors: { id: string }[] }, formId?: string) => void }>('utils/formPresentation.ts', { document })
  focusFormError({ errors: [{ id: 'email' }, { id: 'ignored' }] })
  focusFormError(undefined, 'active-form')
  focusFormError({ errors: [] }, 'closed-form')
  assert.deepEqual(focused, ['email', 'active-form-field'])
})
