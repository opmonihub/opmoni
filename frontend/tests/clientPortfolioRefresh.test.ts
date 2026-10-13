import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const page = readFileSync(new URL('../app/pages/customers/[documento]/[[situacao]].vue', import.meta.url), 'utf8')

test('client mutation handlers refresh displayed rows and their independently cached counters together', () => {
  assert.match(page, /data: summary, refresh: refreshSummary/)
  assert.match(page, /async function refreshPortfolio\(\)\s*\{\s*await Promise\.all\(\[refresh\(\), refreshSummary\(\)\]\)/)
  for (const handler of ['onSaved', 'onDeleted', 'toggleStatus']) {
    const body = page.slice(page.indexOf(`async function ${handler}`)).split('\n}')[0]!
    assert.match(body, /await refreshPortfolio\(\)/, `${handler} must revalidate counters after its mutation`)
  }
  assert.match(page, /@applied="refreshPortfolio"/)
  assert.match(page, /@changed="refreshPortfolio"/)
})
