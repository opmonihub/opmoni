import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { readFileSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { execFileSync } from 'node:child_process'
import { it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'

// The SFC compiler returns dynamic setup bindings; execute the real form and API boundaries.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Runtime = any
const require = createRequire(import.meta.url)
const flush = async () => {
  await Promise.resolve()
  await Vue.nextTick()
  await Promise.resolve()
}
const empty = { configured: false, consumer_key_hint: null, contracting_document: null, certificate_subject: null, certificate_serial: null, certificate_not_before: null, certificate_not_after: null, updated_at: null, certificate_source: 'upload', contracting_account_id: null, legacy_account_certificate: false }
const saved = { ...empty, configured: true, consumer_key_hint: '••••1234', certificate_subject: 'Safe subject' }
const account = (id = 27, status = 'active') => ({ id, name: `Office ${id}`, status, members_count: 0, pending_owner: null })
const vault = { configured: true, revision: 1, document: '12345678000100', subject: 'Safe source', serial: '7', not_before: '2020-01-01', not_after: '2099-01-01', updated_at: '2026-10-17' }
const result = { ok: true, failed_element: null, message: null, checked_at: '2026-10-17' }
function deferred() {
  let resolve!: (value: Runtime) => void
  let reject!: (value: Runtime) => void
  const promise = new Promise((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}
async function page(request: (path: string, options?: Runtime) => Promise<Runtime>) {
  const mounted: (() => unknown)[] = []
  const unmounted: (() => unknown)[] = []
  const toasts: Runtime[] = []
  const states = new Map()
  const globals: Record<string, Runtime> = {
    ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch,
    onMounted: (fn: () => unknown) => mounted.push(fn), onBeforeUnmount: (fn: () => unknown) => unmounted.push(fn),
    useToast: () => ({ add: (value: Runtime) => toasts.push(value) }),
    useRequestFetch: () => request, useRequestEvent: () => undefined,
    useState: (key: string, initial: () => unknown) => {
      if (!states.has(key))
        states.set(key, Vue.ref(key === 'access-csrf' ? 'test-csrf' : initial()))
      return states.get(key)
    }, useNuxtApp: () => ({ $api: globals.useAccessApi().api })

  }
  function evaluate(source: string): Runtime {
    const compiled = ts.transpileModule(source.replaceAll('import.meta.server', 'false'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ESNext } }).outputText
    const exports = {}
    new Function('require', 'exports', ...Object.keys(globals), compiled)((name: string) => {
      if (name === 'vue')
        return { ...Vue, withAsyncContext: (run: () => unknown) => [run(), () => {}], resolveComponent: (name: string) => ({ name }) }
      if (name.startsWith('~/'))
        return evaluate(readFileSync(new URL(`../app/${name.slice(2)}.ts`, import.meta.url), 'utf8'))
      if (name.startsWith('#shared/'))
        return evaluate(readFileSync(new URL(`../shared/${name.slice(8)}.ts`, import.meta.url), 'utf8'))
      if (name.startsWith('./'))
        return evaluate(readFileSync(new URL(`../app/composables/${name.slice(2)}.ts`, import.meta.url), 'utf8'))
      return require(name)
    }, exports, ...Object.values(globals))
    return exports
  }
  globals.useAccessApi = evaluate(readFileSync(new URL('../app/composables/useAccessApi.ts', import.meta.url), 'utf8')).useAccessApi
  globals.useSerpro = evaluate(readFileSync(new URL('../app/composables/useSerpro.ts', import.meta.url), 'utf8')).useSerpro
  const { descriptor } = parse(readFileSync(new URL('../app/pages/admin/serpro.vue', import.meta.url), 'utf8'))
  const script = compileScript(descriptor, { id: 'serpro' })
  const template = compileTemplate({ source: descriptor.template!.content, filename: 'serpro.vue', id: 'serpro', compilerOptions: { bindingMetadata: script.bindings } })
  const scope = Vue.effectScope()
  const state = await scope.run(() => evaluate(script.content).default.setup({}, { expose() {} }))
  const render = evaluate(template.code).render
  function flatten(node: Vue.VNode): Vue.VNode[] {
    const nodes = [node]
    if (Array.isArray(node.children))
      for (const child of node.children) {
        if (Vue.isVNode(child))
          nodes.push(...flatten(child))
      }
    else
      if (node.children && typeof node.children === 'object')
        for (const slot of Object.values(node.children)) {
          if (typeof slot === 'function')
            for (const child of [slot({})].flat())
              if (Vue.isVNode(child))
                nodes.push(...flatten(child))
        }
    return nodes
  }
  return { state, toasts, start: async () => {
    await Promise.all(mounted.map(fn => fn()))
    await flush()
  }, nodes: () => flatten(render({}, [], {}, Vue.proxyRefs(state), {}, {})), stop: () => {
    unmounted.forEach(fn => fn())
    scope.stop()
  } }
}
const name = (node: Vue.VNode) => (node.type as Runtime)?.name
const standard = async (path: string) => {
  if (path.endsWith('/serpro/connection'))
    return { data: empty }
  if (path.endsWith('/contracting-certificate'))
    return { data: vault }
  if (/\/admin\/accounts\/\d+$/.test(path))
    return { data: account() }
  return { data: [account()], meta: { page: 2, per_page: 15, last_page: 3, total: 31 } }
}
it('404/500 initial reads are errors; only a successful configured=false shows the form', async () => {
  for (const statusCode of [404, 500]) {
    const view = await page(async () => {
      throw { statusCode }
    })
    await view.start()
    assert.ok(view.nodes().some(node => name(node) === 'ErrorRetryAlert'))
    assert.equal(view.nodes().some(node => name(node) === 'UForm'), false)
    view.stop()
  }
  const view = await page(standard)
  await view.start()
  assert.equal(view.state.metadata.value.configured, false)
  assert.ok(view.nodes().some(node => name(node) === 'UForm'))
  view.stop()
})
it('refresh retains last safe metadata and rejects stale reads and unmounted responses', async () => {
  let response: Runtime = { data: saved }
  const view = await page(async () => await response)
  await view.start()
  response = Promise.reject({ statusCode: 500 })
  await view.state.load()
  assert.equal(view.state.metadata.value.consumer_key_hint, saved.consumer_key_hint)
  assert.ok(view.nodes().some(node => name(node) === 'ErrorRetryAlert'))
  assert.ok(view.nodes().some(node => name(node) === 'UCard'))
  const old = deferred()
  response = old.promise
  const first = view.state.load()
  response = { data: { ...saved, consumer_key_hint: 'latest' } }
  await view.state.load()
  old.resolve({ data: empty })
  await first
  assert.equal(view.state.metadata.value.consumer_key_hint, 'latest')
  const late = deferred()
  response = late.promise
  const pending = view.state.load()
  view.stop()
  late.resolve({ data: empty })
  await pending
  assert.equal(view.state.metadata.value.consumer_key_hint, 'latest')
})
it('search paginates, selects a non-first Account via detail, reads dedicated metadata and sends only explicit source', async () => {
  const calls: Runtime[] = []
  const view = await page(async (path, options) => {
    calls.push([path, options])
    if (options?.method)
      return { data: saved }
    return standard(path)
  })
  await view.start()
  view.state.certificateSource.value = 'account'
  view.state.accountQuery.value = 'Office'
  await view.state.searchAccounts()
  view.state.accountPage.value = 2
  await flush()
  await view.state.selectAccount(27)
  assert.ok(calls.some(([path, options]) => path.endsWith('/admin/accounts') && options.query.page === 2 && options.query.q === 'Office'))
  assert.ok(calls.some(([path]) => path.endsWith('/admin/accounts/27')))
  assert.ok(calls.some(([path]) => path.endsWith('/admin/accounts/27/contracting-certificate')))
  assert.equal(view.state.sourceMetadata.value.subject, vault.subject)
  await view.nodes().find(node => name(node) === 'UForm')!.props!.onSubmit({ data: { consumer_key: 'new', consumer_secret: 'dummy' } })
  const body = calls.find(([, options]) => options?.method === 'POST')[1].body as FormData
  assert.equal(body.get('_method'), 'PUT')
  assert.equal(body.get('certificate_source'), 'account')
  assert.equal(body.get('contracting_account_id'), '27')
  assert.equal(body.has('use_account_certificate'), false)
  assert.equal(body.has('certificate'), false)
  view.stop()
})
it('missing/suspended sources block save; source/catalog requests reject obsolete responses', async () => {
  let missing = true
  let suspended = false
  const old = deferred()
  let writes = 0
  const view = await page(async (path, options) => {
    if (options?.method) {
      writes++
      return { data: saved }
    }
    if (path.endsWith('/accounts/27'))
      return { data: account(27, suspended ? 'suspended' : 'active') }
    if (path.endsWith('/contracting-certificate'))
      return { data: { ...vault, configured: !missing } }
    if (path.endsWith('/accounts/28'))
      return old.promise
    return standard(path)
  })
  await view.start()
  view.state.certificateSource.value = 'account'
  await view.state.selectAccount(27)
  await view.state.onSubmit({ data: { consumer_key: 'new', consumer_secret: 'dummy' } })
  assert.equal(writes, 0)
  missing = false
  suspended = true
  await view.state.selectAccount(27)
  await view.state.onSubmit({ data: { consumer_key: 'new', consumer_secret: 'dummy' } })
  assert.equal(writes, 0)
  const pending = view.state.selectAccount(28)
  suspended = false
  await view.state.selectAccount(27)
  old.resolve({ data: account(28) })
  await pending
  assert.equal(view.state.selectedAccount.value.id, 27)
  view.stop()
})
it('real passwordless PKCS12 bytes survive both upload boundaries with explicit empty password', async () => {
  const directory = mkdtempSync(join(tmpdir(), 'opmoni-ui-cert-'))
  try {
    execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=Local test', '-keyout', join(directory, 'key.pem'), '-out', join(directory, 'cert.pem'), '-days', '1'], { stdio: 'ignore' })
    execFileSync('openssl', ['pkcs12', '-export', '-inkey', join(directory, 'key.pem'), '-in', join(directory, 'cert.pem'), '-out', join(directory, 'file.p12'), '-passout', 'pass:'], { stdio: 'ignore' })
    const bytes = readFileSync(join(directory, 'file.p12'))
    const file = new File([bytes], 'local.p12', { type: 'application/x-pkcs12' })
    const writes: Runtime[] = []
    const view = await page(async (path, options) => {
      if (options?.method) {
        writes.push([path, options])
        return { data: path.endsWith('/contracting-certificate') ? vault : saved }
      }
      ;
      return standard(path)
    })
    await view.start()
    view.nodes().find(node => name(node) === 'UFileUpload')!.props!['onUpdate:modelValue'](file)
    await view.state.onSubmit({ data: { consumer_key: 'new', consumer_secret: 'dummy', password: '' } })
    view.state.startEditing()
    view.state.certificateSource.value = 'account'
    await view.state.selectAccount(27)
    view.state.sourceCertificate.value = file
    view.state.sourcePassword.value = ''
    await view.state.uploadSource()
    await view.state.saveConnection({ consumer_key: '', certificate_source: 'upload', certificate: file })
    assert.equal(writes.length, 3)
    for (const [, options] of writes) {
      assert.equal(options.method, 'POST')
      assert.equal(options.body.get('_method'), 'PUT')
      assert.equal(options.body.has('password'), true)
      assert.equal(options.body.get('password'), '')
      assert.deepEqual(Buffer.from(await options.body.get('certificate').arrayBuffer()), bytes)
      assert.equal(options.body.get('certificate').name, 'local.p12')
      assert.equal(new Headers(options.headers).has('Content-Type'), false)
      assert.equal(new Headers(options.headers).get('X-CSRF-Token'), 'test-csrf')
      assert.equal(options.retry, 0)
    }
    assert.equal(writes[0][1].body.get('certificate_source'), 'upload')
    assert.equal(view.state.sourceCertificate.value, null)
    assert.equal(view.state.sourcePassword.value, '')
    view.stop()
  } finally {
    rmSync(directory, { recursive: true, force: true })
  }
})
it('legacy metadata stays truthful and secret-only updates omit all source selectors', async () => {
  let write: Runtime
  const view = await page(async (_path, options) => {
    if (options?.method) {
      write = options.body
      return { data: saved }
    }
    ;
    return { data: { ...saved, certificate_source: 'account', contracting_account_id: 1, legacy_account_certificate: true } }
  })
  await view.start()
  view.state.startEditing()
  assert.match(view.state.currentSourceLabel.value, /legado/i)
  assert.equal(view.state.state.consumer_key, '')
  assert.equal(view.state.state.consumer_secret, '')
  await view.state.onSubmit({ data: { consumer_key: '', consumer_secret: 'dummy' } })
  assert.equal(write.has('certificate_source'), false)
  assert.equal(write.has('contracting_account_id'), false)
  assert.equal(write.has('use_account_certificate'), false)
  view.stop()
})
it('invalid files and mapped 422/413 failures retain nonsecret input, guard duplicate submit and clear sensitive material', async () => {
  const pending = deferred()
  let writes = 0
  const view = await page(async (path, options) => {
    if (options?.method) {
      writes++
      return pending.promise
    }
    ;
    return standard(path)
  })
  await view.start()
  view.state.certificate.value = new File([new Uint8Array(2 * 1024 * 1024 + 1)], 'large.p12')
  await view.state.onSubmit({ data: { consumer_key: 'new', consumer_secret: 'dummy', password: '' } })
  assert.equal(writes, 0)
  assert.match(view.state.fieldErrors.value.certificate, /2 MiB/)
  view.state.state.consumer_key = 'typed'
  view.state.state.consumer_secret = 'dummy'
  view.state.state.password = 'dummy'
  view.state.certificate.value = new File(['malformed'], 'bad.p12')
  const first = view.state.onSubmit({ data: { ...view.state.state } })
  await view.state.onSubmit({ data: { ...view.state.state } })
  assert.equal(writes, 1)
  pending.reject({ statusCode: 422, data: { errors: { certificate: ['Arquivo inválido'] } } })
  await first
  assert.equal(view.state.fieldErrors.value.certificate, 'Arquivo inválido')
  assert.equal(view.state.state.consumer_key, 'typed')
  assert.equal(view.state.state.consumer_secret, '')
  assert.equal(view.state.state.password, '')
  assert.equal(view.state.certificate.value, null)
  view.state.state.consumer_secret = 'dummy'
  view.state.sourcePassword.value = 'dummy'
  view.state.sourceCertificate.value = new File(['x'], 'x.p12')
  view.state.cancelEditing()
  assert.equal(view.state.state.consumer_secret, '')
  assert.equal(view.state.sourcePassword.value, '')
  assert.equal(view.state.sourceCertificate.value, null)
  view.stop()
  const oversized = await page(async (path, options) => {
    if (options?.method)
      throw { statusCode: 413, data: { code: 'payload_too_large' } }
    return standard(path)
  })
  await oversized.start()
  oversized.state.certificate.value = new File(['x'], 'x.p12')
  await oversized.state.onSubmit({ data: { consumer_key: 'new', consumer_secret: 'dummy', password: '' } })
  assert.match(oversized.state.operationError.value, /2 MiB|4 MiB/)
  oversized.stop()
})
it('connectivity guards duplicate/overlapping actions, ignores stale results and exposes 409/429 retry', async () => {
  let next: Runtime = deferred()
  let calls = 0
  const view = await page(async (path, options) => {
    if (path.endsWith('/connectivity')) {
      calls++
      return next.promise
    }
    if (options?.method)
      return { data: saved }
    return { data: saved }
  })
  await view.start()
  const first = view.state.onTestConnectivity()
  await view.state.onTestConnectivity()
  await view.state.onSubmit({ data: { consumer_key: '' } })
  assert.equal(calls, 1)
  next.reject({ statusCode: 409, data: { code: 'connection_changed' } })
  await first
  assert.equal(view.state.connectivity.value, null)
  assert.match(view.state.connectivityError.value, /mudou|alterad/i)
  next = deferred()
  const retry = view.state.onTestConnectivity()
  next.reject({ statusCode: 429, data: { code: 'rate_limited' } })
  await retry
  assert.match(view.state.connectivityError.value, /aguarde|limite/i)
  next = deferred()
  const success = view.state.onTestConnectivity()
  next.resolve({ data: result })
  await success
  assert.equal(view.state.connectivity.value.ok, true)
  view.state.startEditing()
  await view.state.onSubmit({ data: { consumer_key: '' } })
  assert.equal(view.state.connectivity.value, null)
  next = deferred()
  const late = view.state.onTestConnectivity()
  view.state.state.consumer_secret = 'dummy'
  view.state.sourcePassword.value = 'dummy'
  view.stop()
  next.resolve({ data: result })
  await late
  assert.equal(view.state.connectivity.value, null)
  assert.equal(view.state.state.consumer_secret, '')
  assert.equal(view.state.sourcePassword.value, '')
  assert.equal(view.state.state.consumer_key, '')
})
it('catalog and selected-source loading block form submission; missing Account retries and catalog discards old errors', async () => {
  let catalog: Runtime = { data: [account()], meta: { page: 1, last_page: 1, per_page: 15, total: 1 } }
  let missing = true
  let writes = 0
  const view = await page(async (path, options) => {
    if (options?.method) {
      writes++
      return { data: saved }
    }
    if (path.endsWith('/admin/accounts'))
      return await catalog
    if (path.endsWith('/accounts/27') && missing)
      throw { statusCode: 404 }
    return standard(path)
  })
  await view.start()
  view.nodes().find(node => name(node) === 'URadioGroup')!.props!['onUpdate:modelValue']('account')
  await view.state.selectAccount(27)
  assert.match(view.state.sourceError.value, /não encontrado/)
  assert.equal(view.state.formDisabled.value, true)
  missing = false
  await view.state.selectAccount(27)
  assert.equal(view.state.sourceUsable.value, true)
  const stale = deferred()
  catalog = stale.promise
  view.state.accountQuery.value = 'old'
  const first = view.state.searchAccounts()
  assert.equal(view.state.formDisabled.value, true)
  await view.state.onSubmit({ data: { consumer_key: 'key', consumer_secret: 'dummy' } })
  assert.equal(writes, 0)
  catalog = { data: [account(31)], meta: { page: 1, last_page: 1, per_page: 15, total: 1 } }
  view.state.accountQuery.value = 'new'
  await view.state.searchAccounts()
  stale.reject(Error('obsolete catalog failure'))
  await first
  assert.equal(view.state.accountResults.value[0].id, 31)
  assert.equal(view.state.catalogError.value, '')
  view.stop()
})
it('inline vault upload guards duplicate actions, maps field failures and cannot repopulate after navigation', async () => {
  let pending = deferred()
  let writes = 0
  const view = await page(async (path, options) => {
    if (options?.method) {
      writes++
      return pending.promise
    }
    return standard(path)
  })
  await view.start()
  view.state.certificateSource.value = 'account'
  await view.state.selectAccount(27)
  view.state.sourceCertificate.value = new File(['local invalid container'], 'bad.p12')
  view.state.sourcePassword.value = 'dummy'
  const first = view.state.uploadSource()
  await view.state.uploadSource()
  await view.state.onTestConnectivity()
  assert.equal(writes, 1)
  pending.reject({ statusCode: 422, data: { errors: { password: ['Senha incorreta'] } } })
  await first
  assert.equal(view.state.sourceFieldErrors.value.password, 'Senha incorreta')
  assert.equal(view.state.sourcePassword.value, '')
  assert.equal(view.state.sourceCertificate.value, null)
  assert.equal(view.state.selectedAccount.value.id, 27)
  pending = deferred()
  view.state.sourceCertificate.value = new File(['local test'], 'retry.p12')
  const late = view.state.uploadSource()
  view.state.state.consumer_key = 'typed-dummy-key'
  view.stop()
  pending.resolve({ data: vault })
  await late
  assert.equal(view.state.sourceCertificate.value, null)
  assert.equal(view.state.state.consumer_key, '')
  assert.equal(view.toasts.length, 0)
})
