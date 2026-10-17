import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { describe, it } from 'node:test'

describe('useErpCompanySettings API wire', () => {
  it('calls GET/PATCH /erp/company/settings via useAccessApi', async () => {
    const src = await readFile(new URL('../app/composables/useErpCompanySettings.ts', import.meta.url), 'utf8')
    assert.match(src, /useAccessApi\(\)/)
    assert.match(src, /'\/erp\/company\/settings'/)
    assert.match(src, /method:\s*'PATCH'/)
  })
})
