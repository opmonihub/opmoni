import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  pinnedDocumentFilter,
  singleValueFacets,
  tagFacets,
  withoutPinnedDocumentFilter
} from '../app/utils/portfolioFilters.ts'

describe('portfolio filters follow the open view', () => {
  it('leaves both document filters available on Todos', () => {
    assert.equal(pinnedDocumentFilter({ document: 'certificate', status: 'all' }), null)
    assert.equal(pinnedDocumentFilter({ document: 'poa', status: 'all' }), null)
  })

  it('pins the certificate filter on a certificate status view', () => {
    assert.equal(pinnedDocumentFilter({ document: 'certificate', status: 'valid' }), 'certificate')
    assert.equal(pinnedDocumentFilter({ document: 'certificate', status: 'expiring' }), 'certificate')
    assert.equal(pinnedDocumentFilter({ document: 'certificate', status: 'expired' }), 'certificate')
    assert.equal(pinnedDocumentFilter({ document: 'certificate', status: 'missing' }), 'certificate')
  })

  it('pins the power of attorney filter on a procuração view', () => {
    assert.equal(pinnedDocumentFilter({ document: 'poa', status: 'valid' }), 'poa')
    assert.equal(pinnedDocumentFilter({ document: 'poa', status: 'expired' }), 'poa')
  })

  it('drops the pinned filter and keeps the other document', () => {
    const filters = {
      certificate: ['expired', 'missing'],
      poa: ['valid']
    }

    assert.deepEqual(
      withoutPinnedDocumentFilter(filters, 'certificate'),
      { certificate: [], poa: ['valid'] }
    )
    assert.deepEqual(filters.certificate, ['expired', 'missing'])
    assert.deepEqual(
      withoutPinnedDocumentFilter(filters, 'poa'),
      { certificate: ['expired', 'missing'], poa: [] }
    )
    assert.deepEqual(withoutPinnedDocumentFilter(filters, null), filters)
  })

  it('hides a column whose values no longer split the rows', () => {
    assert.deepEqual(singleValueFacets(['valid', 'valid']), [])
    assert.deepEqual(singleValueFacets(['valid', 'expired']).sort(), ['expired', 'valid'])
    assert.deepEqual(singleValueFacets([null, undefined, '']), [])
  })

  it('keeps only tags that some clients have and others do not', () => {
    const rows = [
      { tags: [{ id: 1 }, { id: 2 }] },
      { tags: [{ id: 1 }] },
      { tags: [{ id: 1 }, { id: 3 }] }
    ]

    assert.deepEqual(tagFacets(rows).sort(), [2, 3])
    assert.deepEqual(tagFacets([{ tags: [{ id: 1 }] }, { tags: [{ id: 1 }] }]), [])
  })
})
