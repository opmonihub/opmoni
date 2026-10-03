import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { adminListParams, createLatestRequestRunner, pageWithinLastPage, readPaginatedMeta } from '../app/utils/adminListFilters.ts'

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => {
    resolve = done
  })

  return { promise, resolve }
}

describe('admin list filters', () => {
  it('omits empty search and the all option', () => {
    assert.deepEqual(adminListParams(2, '   ', 'status', 'all'), { page: 2 })
  })

  it('trims search and sends the selected filter using its API key', () => {
    assert.deepEqual(adminListParams(1, '  agulha  ', 'type', 'super'), {
      page: 1,
      q: 'agulha',
      type: 'super'
    })
  })

  it('moves a page back when removing its final filtered result', () => {
    assert.equal(pageWithinLastPage(3, 2), 2)
    assert.equal(pageWithinLastPage(1, 0), 1)
    assert.equal(pageWithinLastPage(2, 3), 2)
    assert.equal(pageWithinLastPage(1, Number.NaN), 1)
  })

  it('reads Laravel paginator meta from nested meta', () => {
    assert.deepEqual(readPaginatedMeta({
      data: [],
      meta: { current_page: 2, last_page: 5, per_page: 15, total: 42 }
    }), {
      current_page: 2,
      last_page: 5,
      per_page: 15,
      total: 42
    })
  })

  it('commits and settles only the latest request when responses arrive out of order', async () => {
    const first = deferred<string>()
    const second = deferred<string>()
    const events: string[] = []
    const runLatest = createLatestRequestRunner()
    const handlers = {
      onSuccess: (value: string) => events.push(`success:${value}`),
      onError: () => events.push('error'),
      onSettled: () => events.push('settled')
    }

    const firstRun = runLatest(() => first.promise, handlers)
    const secondRun = runLatest(() => second.promise, handlers)

    second.resolve('new')
    await secondRun
    first.resolve('old')
    await firstRun

    assert.deepEqual(events, ['success:new', 'settled'])
  })
})
