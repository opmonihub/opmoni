import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { formatErpCatalogStatus } from '../shared/utils/erpCatalogs.ts'

describe('ERP catalog status labels', () => {
  it('shows catalog states in Portuguese while preserving unknown API values', () => {
    assert.equal(formatErpCatalogStatus('active'), 'Ativo')
    assert.equal(formatErpCatalogStatus('archived'), 'Arquivado')
    assert.equal(formatErpCatalogStatus('inactive'), 'Inativo')
    assert.equal(formatErpCatalogStatus('future'), 'future')
    assert.equal(formatErpCatalogStatus(null), '—')
  })
})
