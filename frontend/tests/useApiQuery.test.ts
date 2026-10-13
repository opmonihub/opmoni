import test from 'node:test'
import assert from 'node:assert/strict'
import { queryOf } from '../app/composables/useApiQuery.ts'

test('serializa array como key[] e pula null/undefined/vazio', () => {
  assert.deepEqual(queryOf({ tag: ['a', 'b'], x: null, y: undefined, z: '' }), { 'tag[]': ['a', 'b'] })
})
