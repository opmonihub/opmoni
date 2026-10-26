import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createSettingsFieldDraft } from '../app/utils/settingsFieldDraft.ts'

test('field reconciliation merges untouched values and retains dirty fields across adjacent saves', () => {
  const form: Record<string, unknown> = {}
  const draft = createSettingsFieldDraft(form)
  draft.reconcile({ name: 'Original', code: '01', rate: '9007199254740993.01' })
  form.name = 'Draft'
  assert.deepEqual(draft.reconcile({ name: 'Original', code: '02', rate: '9007199254740993.01' }), [])
  assert.deepEqual(form, { name: 'Draft', code: '02', rate: '9007199254740993.01' })
})

test('remote dirty-field conflicts persist until review or matching confirmation', () => {
  const form: Record<string, unknown> = {}
  const draft = createSettingsFieldDraft(form)
  draft.reconcile({ name: 'Original', code: '01' })
  form.name = 'Draft'
  assert.deepEqual(draft.reconcile({ name: 'Remote', code: '02' }), ['name'])
  assert.equal(form.name, 'Draft')
  assert.deepEqual(draft.reconcile({ name: 'Remote', code: '03' }), ['name'])
  draft.reset({ name: 'Remote', code: '03' })
  assert.equal(form.name, 'Remote')
  assert.deepEqual(draft.reconcile({ name: 'Remote', code: '03' }), [])
})

test('own confirmation advances submitted fields without replacing edits made while saving', () => {
  const form: Record<string, unknown> = {}
  const draft = createSettingsFieldDraft(form)
  draft.reconcile({ name: 'Original', code: '01' })
  form.name = 'Submitted'
  form.code = '02'
  const submitted = { ...form }
  form.name = 'Newer draft'
  draft.reconcile({ name: 'Confirmed', code: '02' })
  draft.confirm({ name: 'Confirmed', code: '02' }, submitted)
  assert.equal(form.name, 'Newer draft')
  assert.equal(form.code, '02')
  assert.deepEqual(draft.reconcile({ name: 'Confirmed', code: '02' }), [])
  draft.reset()
  assert.deepEqual(form, {})
})
