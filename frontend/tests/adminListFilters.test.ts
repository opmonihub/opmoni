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

it('reads canonical platform account pagination without changing Laravel resource pagination', () => {
  assert.deepEqual(readPaginatedMeta({ meta: { page: 2, per_page: 15, total: 31 } }), {
    current_page: 2, last_page: 3, per_page: 15, total: 31
  })
})

it('normalizes supported Admin list criteria without retaining records or unsupported filter values', async () => {
  const { adminListNavigationOptions } = await import('../app/utils/adminListFilters.ts')
  const options = adminListNavigationOptions(['all', 'active', 'canceled'])
  assert.deepEqual(options.normalize({ q: 'Needle', page: 3, filter: 'active', records: [{ id: 1 }] }), { q: 'Needle', page: 3, filter: 'active' })
  assert.deepEqual(options.normalize({ q: null, page: 0, filter: 'unsupported' }), { q: '', page: 1, filter: 'all' })
})

it('invalidates an active read signal without settling the departed view', async () => {
  const pending = deferred<string>()
  const events: string[] = []
  let signal!: AbortSignal
  const runner = createLatestRequestRunner()
  const read = runner((value) => {
    signal = value
    return pending.promise
  }, { onSuccess: value => events.push(value), onError: () => events.push('error'), onSettled: () => events.push('settled') })
  runner.invalidate()
  assert.equal(signal.aborted, true)
  pending.resolve('obsolete')
  await read
  assert.deepEqual(events, [])
})
