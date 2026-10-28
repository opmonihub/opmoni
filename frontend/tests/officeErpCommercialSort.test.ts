import assert from 'node:assert/strict'
import { test } from 'node:test'
import { officeErpSortParams } from '../app/utils/officeErpListSort.ts'

test('commercial ordering sends only allowlisted fields and omits the backend default', () => {
  assert.deepEqual(officeErpSortParams('clients', '', 'desc'), {})
  assert.deepEqual(officeErpSortParams('clients', 'name', 'desc'), { sort: 'name', direction: 'desc' })
  assert.deepEqual(officeErpSortParams('contracts', 'client', 'asc'), { sort: 'client', direction: 'asc' })
  assert.deepEqual(officeErpSortParams('plans', 'tax_id', 'asc'), {})
  assert.deepEqual(officeErpSortParams('plans', 'status', 'sideways'), { sort: 'status', direction: 'asc' })
})
