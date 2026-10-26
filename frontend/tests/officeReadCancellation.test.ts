import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

test('office safe readers forward owner cancellation through the existing API transport', async () => {
  const received: { path: string, signal?: AbortSignal, method?: string }[] = []
  const bindings = { useNuxtApp: () => ({ $api: async (path: string, options: { signal?: AbortSignal, method?: string } = {}) => {
    received.push({ path, ...options })
    return { data: [] }
  } }) }
  const signal = new AbortController().signal
  const options = { signal }
  for (const [file, factory, reads] of [
    ['useWork', 'useWork', [['listTemplates', [options]], ['showTemplate', [1, options]], ['previewTemplate', [1, options]], ['listProcesses', [{}, options]], ['showProcess', [1, options]], ['listTasks', [{}, options]], ['calendar', ['2027-02-01', '2027-02-28', {}, options]], ['grouped', ['2027-02', options]], ['unscopedTasks', ['2027-02', options]]]],
    ['useFiscal', 'useFiscal', [['summary', [options]], ['clients', [{}, options]], ['list', [{}, options]]]],
    ['useClients', 'useClients', [['list', [{}, options]], ['show', [1, options]], ['portfolioSummary', [{}, options]], ['portfolioAnalytics', [{}, options]], ['listTags', [options]]]],
    ['useDispatchContacts', 'useDispatchContacts', [['list', [1, options]]]],
    ['useDepartments', 'useDepartments', [['list', [{}, options]]]],
    ['useMembers', 'useMembers', [['listDirectory', [{}, options]]]],
    ['useSerpro', 'useSerpro', [['overview', [options]], ['listObligation', ['declaracoes/dctf', {}, options]], ['connection', [options]], ['contractingCertificate', [1, options]]]]
  ] as [string, string, [string, unknown[]][]][]) {
    const module = loadAppComposable<Record<string, () => Record<string, (...args: unknown[]) => Promise<unknown>>>>(`composables/${file}.ts`, bindings)
    const api = module[factory]!()
    for (const [method, args] of reads) {
      await api[method]!(...args)
      assert.equal(received.at(-1)?.signal, signal, `${file}.${method}`)
      assert.equal(received.at(-1)?.method, undefined, 'Only safe default GETs are covered')
    }
  }
})
