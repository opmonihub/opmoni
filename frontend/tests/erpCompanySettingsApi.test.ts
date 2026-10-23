/* eslint-disable @stylistic/max-statements-per-line -- Compact callbacks keep the local mounted renderer beside its authority assertions. */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import * as Vue from 'vue'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { apiFailure } from '../shared/utils/access.ts'
import { erpCompanyHasPermission } from '../app/utils/erpModulesNav.ts'
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

function settingsHarness(api: (path: string, options?: Record<string, unknown>) => Promise<unknown>, role = 'owner', permissions = ['*'], target: 'section' | 'email' = 'section') {
  const user = Vue.ref({ id: 91 })
  const company = Vue.ref({ id: 7, status: 'active', access_kind: 'direct', role, permissions, delegated_office_id: null as number | null })
  const account = Vue.ref({ id: 3, status: 'active', role: 'admin' })
  const session = Vue.ref(0)
  const key = Vue.computed(() => JSON.stringify([user.value, company.value, account.value, session.value]))
  const context = { key, capture: () => ({ key: key.value }), isCurrent: (token: { key: string }) => token.key === key.value }
  const cache = new Map<string, Vue.Ref>()
  const notices: unknown[] = []
  let privateForm: Record<string, unknown> | null = null
  function compile(source: string, imports: Record<string, unknown>) {
    const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
    const exports: Record<string, unknown> = {}
    const bindings = { captureSettingsForm: (value: Record<string, unknown>) => { privateForm = value }, definePageMeta: () => {}, ref: Vue.ref, reactive: Vue.reactive, computed: Vue.computed, watch: Vue.watch, onMounted: Vue.onMounted, onScopeDispose: Vue.onScopeDispose,
      useState: (name: string, init: () => unknown) => { if (!cache.has(name)) cache.set(name, Vue.ref(init())); return cache.get(name) },
      useAccessApi: () => ({ api }), useAuth: () => ({ user, currentCompany: company, currentAccount: account }), useErpAdministrationContext: () => context, useToast: () => ({ add: (notice: unknown) => notices.push(notice) }),
      useErpCompanySettings: () => state }
    new Function('require', 'exports', ...Object.keys(bindings), output)((path: string) => { if (!(path in imports)) throw new Error(`Unexpected settings import ${path}`); return imports[path] }, exports, ...Object.values(bindings))
    return exports
  }
  const scope = Vue.effectScope()
  const exports = compile(readFileSync(new URL('../app/composables/useErpCompanySettings.ts', import.meta.url), 'utf8'), { '~/utils/erpModulesNav': { erpCompanyHasPermission } })
  // eslint-disable-next-line @typescript-eslint/no-explicit-any -- Runtime-only actual composable harness.
  const state = scope.run(() => (exports.useErpCompanySettings as () => Record<string, any>)())!
  const pagePath = target === 'email' ? '../app/pages/erp/configuracoes/email.vue' : '../app/components/erp/ErpSettingsSectionForm.vue'
  const source = compileScript(parse(readFileSync(new URL(pagePath, import.meta.url), 'utf8')).descriptor, { id: 'settings-native', inlineTemplate: true })
  const instrumented = source.content.replace('return (_ctx:', 'captureSettingsForm(form)\nreturn (_ctx:')
  assert.notEqual(instrumented, source.content, 'capture the actual reactive form before its compiled render closure')
  const component = compile(instrumented, { 'vue': Vue, '#shared/utils/access': { apiFailure } }).default as Vue.Component
  type Node = { tag?: string, text?: string, children: Node[], parent: Node | null, props: Record<string, unknown> }
  const root: Node = { tag: 'root', children: [], parent: null, props: {} }
  const renderer = Vue.createRenderer<Node>({
    patchProp(node, name, _previous, next) { node.props[name] = next },
    insert(node, parent, anchor) { if (node.parent) { const previous = node.parent.children.indexOf(node); if (previous >= 0) node.parent.children.splice(previous, 1) } node.parent = parent; const index = anchor ? parent.children.indexOf(anchor) : -1; if (index < 0) parent.children.push(node); else parent.children.splice(index, 0, node) },
    remove(node) { if (node.parent) { const index = node.parent.children.indexOf(node); if (index >= 0) node.parent.children.splice(index, 1) } },
    createElement(tag) { return { tag, children: [], parent: null, props: {} } }, createText(text) { return { text, children: [], parent: null, props: {} } }, createComment(text) { return { text, children: [], parent: null, props: {} } },
    setText(node, text) { node.text = text }, setElementText(node, text) { node.children = []; node.text = text }, parentNode(node) { return node.parent }, nextSibling(node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  const app = renderer.createApp({ render: () => Vue.h(component, { title: 'Sistema', fields: [{ key: 'name', label: 'Nome', description: 'Nome exibido pela empresa.', hint: 'Obrigatório' }, { key: 'regime', label: 'Regime', type: 'select', options: [{ label: 'Normal', value: 'normal' }] }, { key: 'enabled', label: 'Ativo', type: 'checkbox' }] }) })
  for (const name of ['UPageCard', 'USeparator']) app.component(name, { setup: (_props, ctx) => () => Vue.h('div', ctx.slots.default?.()) })
  app.component('UFormField', { props: ['name', 'label', 'description', 'hint', 'error'], setup: (props, ctx) => () => Vue.h('fieldset', { name: props.name }, [Vue.h('label', String(props.label ?? '')), props.description ? Vue.h('p', String(props.description)) : null, props.hint ? Vue.h('span', String(props.hint)) : null, ctx.slots.default?.(), props.error ? Vue.h('p', { role: 'alert' }, String(props.error)) : null]) })
  app.component('UForm', { setup: (_props, ctx) => () => Vue.h('form', { ...ctx.attrs }, ctx.slots.default?.()) })
  app.component('UButton', { props: ['label'], setup: (props, ctx) => () => Vue.h('button', ctx.attrs, String(props.label ?? '')) })
  for (const name of ['UInput', 'USelect', 'UCheckbox']) app.component(name, { props: ['modelValue'], emits: ['update:modelValue'], setup: (props, ctx) => () => Vue.h(name === 'USelect' ? 'select' : 'input', { ...ctx.attrs, value: props.modelValue, onInput: (value: string) => ctx.emit('update:modelValue', value) }) })
  const errors: unknown[] = []
  app.config.errorHandler = error => errors.push(error)
  app.mount(root)
  const walk = (node: Node): Node[] => [node, ...node.children.flatMap(walk)]
  const submit = () => (walk(root).find(node => node.tag === 'form')!.props.onSubmit as (event: unknown) => unknown)({ preventDefault() {} })
  return { state, company, account, session, root, walk, submit, notices, errors, privateForm: () => privateForm, dispose: () => { app.unmount(); scope.stop() } }
}

const settingsResponse = { data: { name: 'Empresa autorizada', cnpj: '30288513000100', razao_social: null, ie: null, status: 'active', regime: 'normal', enabled: 1 }, meta: {} }
const flushSettings = async () => { await new Promise(resolve => setImmediate(resolve)); await Vue.nextTick() }

describe('native acceptance settings authority', () => {
  it('renders operator/viewer without write grants as read-only and rejects programmatic submit', async () => {
    for (const role of ['operator', 'viewer']) {
      const calls: Array<Record<string, unknown> | undefined> = []
      const mounted = settingsHarness(async (_path, options) => { calls.push(options); return settingsResponse }, role, ['erp.company.settings.show'])
      try {
        await flushSettings()
        const controls = mounted.walk(mounted.root).filter(node => ['input', 'select'].includes(node.tag ?? ''))
        assert.equal(controls.length, 3)
        assert.ok(controls.every(node => node.props.disabled === true), 'all settings controls must reflect current write authority')
        assert.equal(mounted.walk(mounted.root).some(node => node.tag === 'button' && node.text === 'Salvar'), false)
        await mounted.submit()
        assert.equal(calls.filter(call => call?.method === 'PATCH').length, 0)
        assert.deepEqual(mounted.notices, [])
      } finally { mounted.dispose() }
    }
  })

  it('admits current writers and drops late PATCH/GET after authority or session changes', async () => {
    let finish!: (value: unknown) => void
    const writes: unknown[] = []
    const mounted = settingsHarness(async (_path, options) => { if (options?.method === 'PATCH') { writes.push(options.body); return new Promise((resolve) => { finish = resolve }) } return settingsResponse })
    try {
      await flushSettings()
      const request = mounted.submit()
      await mounted.submit()
      assert.equal(writes.length, 1)
      mounted.company.value.permissions = ['erp.company.settings.show']
      assert.equal(mounted.state.state.value, null, 'private shared settings are cleared synchronously on revocation')
      finish(settingsResponse)
      await request
      await flushSettings()
      assert.equal(mounted.state.state.value, null)
      assert.deepEqual(mounted.notices, [], 'stale success cannot produce a success toast')
      let finishRead!: (value: unknown) => void
      const late = settingsHarness(async () => new Promise((resolve) => { finishRead = resolve }))
      try { late.session.value++; finishRead(settingsResponse); await flushSettings(); assert.equal(late.state.state.value, null) } finally { late.dispose() }
    } finally { mounted.dispose() }
  })
})

describe('native acceptance settings writer roles and refusals', () => {
  it('preserves owner/manager and explicitly granted operator writes, with viewer remaining read-only', async () => {
    for (const role of ['owner', 'manager', 'operator', 'viewer']) {
      const writes: unknown[] = []
      const mounted = settingsHarness(async (_path, options) => { if (options?.method === 'PATCH') writes.push(options.body); return settingsResponse }, role)
      try {
        await flushSettings()
        await mounted.submit()
        assert.equal(writes.length, role === 'viewer' ? 0 : 1)
        assert.equal(mounted.notices.length, role === 'viewer' ? 0 : 1)
        assert.deepEqual(mounted.errors, [])
      } finally { mounted.dispose() }
    }
  })

  it('intersects delegated write grants with active matching office writer role', async () => {
    const writes: unknown[] = []
    const mounted = settingsHarness(async (_path, options) => { if (options?.method === 'PATCH') writes.push(options.body); return settingsResponse })
    try {
      await flushSettings()
      mounted.company.value.access_kind = 'delegated'
      mounted.company.value.delegated_office_id = 3
      mounted.account.value.role = 'usuario'
      await mounted.state.refresh()
      await mounted.submit()
      assert.equal(writes.length, 0)
      for (const role of ['admin', 'operador']) {
        mounted.account.value.role = role
        await mounted.state.refresh()
        await mounted.submit()
      }
      assert.equal(writes.length, 2)
      mounted.account.value.id = 4
      assert.equal(mounted.state.canWrite.value, false)
      await mounted.state.save({ name: 'Forbidden' })
      mounted.account.value.id = 3
      mounted.account.value.status = 'inactive'
      assert.equal(mounted.state.canWrite.value, false)
      await mounted.state.save({ name: 'Forbidden' })
      assert.equal(writes.length, 2)
    } finally { mounted.dispose() }
  })

  it('clears data and blocks a definitive current refusal, and disables unknown failed reads', async () => {
    let deny = false
    let writes = 0
    const mounted = settingsHarness(async (_path, options) => { if (options?.method === 'PATCH') { writes++; if (deny) throw { statusCode: 403 } } return settingsResponse })
    try {
      await flushSettings()
      deny = true
      await mounted.submit()
      await flushSettings()
      assert.equal(mounted.state.state.value, null)
      assert.equal(mounted.state.canWrite.value, false)
      await mounted.submit()
      assert.equal(writes, 1)
      assert.ok(mounted.walk(mounted.root).filter(node => node.tag === 'input').every(node => node.props.disabled === true && [false, '', 0].includes(node.props.value as never)))
    } finally { mounted.dispose() }
    const failed = settingsHarness(async () => { throw new Error('Offline') })
    try {
      await flushSettings()
      assert.equal(failed.state.state.value, null)
      assert.ok(failed.walk(failed.root).filter(node => ['input', 'select'].includes(node.tag ?? '')).every(node => node.props.disabled === true))
      assert.deepEqual(failed.errors, [])
    } finally { failed.dispose() }
  })
})

describe('native acceptance email consumer review correction', () => {
  const emailSettings = { mail_host: 'smtp.example.test', mail_port: '587', mail_from_address: 'erp@example.test', mail_from_name: 'Empresa autorizada' }
  const response = { ...settingsResponse, data: { ...settingsResponse.data, email_settings: emailSettings } }
  const controls = (mounted: ReturnType<typeof settingsHarness>) => mounted.walk(mounted.root).filter(node => node.tag === 'input')

  it('renders the actual email page read-only and performs no denied programmatic PATCH or confirmation', async () => {
    for (const role of ['operator', 'viewer']) {
      const writes: unknown[] = []
      const mounted = settingsHarness(async (_path, options) => { if (options?.method === 'PATCH') writes.push(options.body); return response }, role, ['erp.company.settings.show'], 'email')
      try {
        await flushSettings()
        assert.equal(controls(mounted).length, 4)
        assert.ok(controls(mounted).every(node => node.props.disabled === true))
        assert.equal(mounted.walk(mounted.root).some(node => node.tag === 'button' && node.text === 'Salvar'), false)
        await mounted.submit()
        assert.deepEqual(writes, [])
        assert.deepEqual(mounted.notices, [])
      } finally { mounted.dispose() }
    }
  })

  it('blocks unknown and busy email submissions without a request or skipped-save success toast', async () => {
    let finishRead!: (value: unknown) => void
    let finishWrite!: (value: unknown) => void
    const writes: unknown[] = []
    const mounted = settingsHarness(async (_path, options) => {
      if (options?.method === 'PATCH') { writes.push(options.body); return new Promise((resolve) => { finishWrite = resolve }) }
      return new Promise((resolve) => { finishRead = resolve })
    }, 'owner', ['*'], 'email')
    try {
      await mounted.submit()
      assert.deepEqual(writes, [])
      assert.deepEqual(mounted.notices, [], 'a skipped save during initial read must not confirm success')
      assert.ok(controls(mounted).every(node => node.props.disabled === true))
      finishRead(response)
      await flushSettings()
      const first = mounted.submit()
      await mounted.submit()
      assert.equal(writes.length, 1)
      assert.deepEqual(mounted.notices, [], 'duplicate busy submit is not a successful save')
      finishWrite(response)
      await first
      assert.deepEqual(mounted.notices, [{ title: 'E-mail salvo', color: 'success', icon: 'i-lucide-check' }])
    } finally { mounted.dispose() }
  })

  it('clears private email inputs synchronously and emits no toast for obsolete PATCH success or failure', async () => {
    for (const outcome of ['success', 'failure']) {
      for (const boundary of ['company', 'session', 'permission']) {
        let finish!: (value: unknown) => void
        let fail!: (reason: unknown) => void
        const mounted = settingsHarness(async (_path, options) => {
          if (options?.method === 'PATCH') return new Promise((resolve, reject) => { finish = resolve; fail = reject })
          return response
        }, 'owner', ['*'], 'email')
        try {
          await flushSettings()
          assert.equal(mounted.privateForm()?.mail_host, emailSettings.mail_host)
          ;(controls(mounted)[0]!.props.onInput as (value: string) => void)('unsaved-private.example.test')
          const request = mounted.submit()
          if (boundary === 'company') mounted.company.value.id++
          else if (boundary === 'session') mounted.session.value++
          else mounted.company.value.permissions = ['erp.company.settings.show']
          assert.deepEqual(Object.values(mounted.privateForm()!), ['', '', '', ''], `${boundary} clears the actual private reactive form before the next render`)
          if (outcome === 'success') finish(response)
          else fail({ statusCode: 422, message: 'Obsolete email error' })
          await request
          await flushSettings()
          assert.ok(controls(mounted).every(node => node.props.value === '' && node.props.disabled === true))
          assert.deepEqual(mounted.notices, [], `${boundary}/${outcome} cannot confirm or report an obsolete operation`)
          assert.deepEqual(mounted.errors, [])
        } finally { mounted.dispose() }
      }
    }
    let finishRead!: (value: unknown) => void
    const lateRead = settingsHarness(async () => new Promise((resolve) => { finishRead = resolve }), 'owner', ['*'], 'email')
    try {
      lateRead.company.value.id++
      finishRead(response)
      await flushSettings()
      assert.ok(controls(lateRead).every(node => node.props.value === ''))
      assert.deepEqual(lateRead.notices, [])
    } finally { lateRead.dispose() }
  })

  it('submits the accepted nested email_settings fields and confirms only an authorized actual response', async () => {
    for (const role of ['owner', 'manager']) {
      const writes: unknown[] = []
      const mounted = settingsHarness(async (_path, options) => { if (options?.method === 'PATCH') writes.push(options.body); return response }, role, ['*'], 'email')
      try {
        await flushSettings()
        assert.ok(controls(mounted).every(node => node.props.disabled !== true))
        const nextValues = ['smtp.new.example.test', '2525', 'new@example.test', 'Novo remetente']
        for (const [index, input] of controls(mounted).entries()) (input.props.onInput as (value: string) => void)(nextValues[index]!)
        await mounted.submit()
        assert.deepEqual(writes, [{ email_settings: { mail_host: nextValues[0], mail_port: nextValues[1], mail_from_address: nextValues[2], mail_from_name: nextValues[3] } }])
        assert.deepEqual(mounted.notices, [{ title: 'E-mail salvo', color: 'success', icon: 'i-lucide-check' }])
        assert.deepEqual(mounted.errors, [])
      } finally { mounted.dispose() }
    }
  })

  it('keeps an unknown failed email read disabled and offers an authorized read retry', async () => {
    let reads = 0
    const mounted = settingsHarness(async () => { if (++reads === 1) throw new Error('Offline'); return response }, 'owner', ['*'], 'email')
    try {
      await flushSettings()
      assert.ok(controls(mounted).every(node => node.props.disabled === true))
      const retry = mounted.walk(mounted.root).find(node => node.tag === 'button' && node.text === 'Atualizar configurações')
      assert.ok(retry)
      await (retry.props.onClick as () => Promise<void>)()
      await flushSettings()
      assert.equal(reads, 2)
      assert.ok(controls(mounted).every(node => node.props.disabled !== true))
      assert.equal(controls(mounted)[0]?.props.value, emailSettings.mail_host)
      assert.deepEqual(mounted.notices, [])
      assert.deepEqual(mounted.errors, [])
    } finally { mounted.dispose() }
  })
})

const settingsText = (mounted: ReturnType<typeof settingsHarness>) => mounted.walk(mounted.root).map(node => node.text ?? '').join(' ')
const settingsField = (mounted: ReturnType<typeof settingsHarness>, name: string) => mounted.walk(mounted.root).find(node => node.tag === 'fieldset' && node.props.name === name)!
const validationFailure = (errors: Record<string, string[]>) => ({ statusCode: 422, data: { message: 'Confira os dados informados.', errors } })

describe('settings rejected save feedback', () => {
  for (const target of ['section', 'email'] as const) {
    const key = target === 'email' ? 'mail_host' : 'name'
    const response = { ...settingsResponse, data: { ...settingsResponse.data, email_settings: { mail_host: 'smtp.example.test' } } }

    it(`${target} binds current form state and preserves field metadata`, async () => {
      const mounted = settingsHarness(async () => response, 'owner', ['*'], target)
      try {
        await flushSettings()
        assert.equal(mounted.walk(mounted.root).find(node => node.tag === 'form')?.props.state, mounted.privateForm())
        if (target === 'section') {
          const text = mounted.walk(settingsField(mounted, key)).map(node => node.text ?? '').join(' ')
          assert.match(text, /Nome exibido pela empresa/)
          assert.match(text, /Obrigatório/)
        }
      } finally { mounted.dispose() }
    })

    it(`${target} renders the first named 422 error, retains edits and clears feedback on retry`, async () => {
      let reject!: (error: unknown) => void
      const writes: unknown[] = []
      const mounted = settingsHarness(async (_path, options) => {
        if (options?.method === 'PATCH') { writes.push(options.body); return new Promise((_resolve, fail) => { reject = fail }) }
        return response
      }, 'owner', ['*'], target)
      try {
        await flushSettings()
        mounted.privateForm()![key] = 'edição preservada'
        const first = mounted.submit()
        await mounted.submit()
        assert.equal(writes.length, 1)
        reject(validationFailure({ [target === 'email' ? `email_settings.${key}` : key]: ['Valor recusado.', 'Outra mensagem.'] }))
        await first
        await flushSettings()
        assert.equal(mounted.privateForm()![key], 'edição preservada')
        const fieldText = mounted.walk(settingsField(mounted, key)).map(node => node.text ?? '').join(' ')
        assert.match(fieldText, /Valor recusado/)
        assert.doesNotMatch(fieldText, /Outra mensagem/)
        assert.deepEqual(mounted.notices.filter(notice => (notice as { color: string }).color === 'success'), [])
        const retry = mounted.submit()
        await flushSettings()
        assert.doesNotMatch(settingsText(mounted), /Valor recusado/)
        reject(new Error('Offline'))
        await retry
        await flushSettings()
        assert.match(settingsText(mounted), /Serviço temporariamente indisponível/)
        assert.equal(mounted.privateForm()![key], 'edição preservada')
        assert.deepEqual(writes[0], target === 'email' ? { email_settings: { mail_host: 'edição preservada', mail_port: '', mail_from_address: '', mail_from_name: '' } } : { name: 'edição preservada', regime: 'normal', enabled: 1 })
      } finally { mounted.dispose() }
    })

    for (const failure of [validationFailure({ settings: ['Configuração incompatível.'] }), { statusCode: 409, data: { message: 'Tente novamente após atualizar.' } }, new Error('Offline')]) {
      it(`${target} visibly reports a form or transport failure`, async () => {
        const mounted = settingsHarness(async (_path, options) => { if (options?.method === 'PATCH') throw failure; return response }, 'owner', ['*'], target)
        try {
          await flushSettings()
          mounted.privateForm()![key] = 'edição preservada'
          await mounted.submit()
          await flushSettings()
          assert.match(settingsText(mounted), new RegExp(apiFailure(failure).message.replaceAll('.', '\\.')))
          assert.equal(mounted.privateForm()![key], 'edição preservada')
          assert.ok(mounted.walk(mounted.root).some(node => node.props.role === 'alert'))
        } finally { mounted.dispose() }
      })
    }

    for (const refusal of [{ statusCode: 401 }, { statusCode: 403 }, { statusCode: 409, data: { code: 'company_context_changed' } }]) {
      it(`${target} clears old feedback on a same-context ${refusal.statusCode} authority refusal`, async () => {
        let refuseRead = false
        const mounted = settingsHarness(async (_path, options) => {
          if (options?.method === 'PATCH') throw validationFailure({ [target === 'email' ? `email_settings.${key}` : key]: ['Valor recusado.'] })
          if (refuseRead) throw refusal
          return response
        }, 'owner', ['*'], target)
        try {
          await flushSettings()
          await mounted.submit()
          await flushSettings()
          assert.match(settingsText(mounted), /Valor recusado/)
          const authority = mounted.state.authorityKey.value
          refuseRead = true
          await assert.rejects(mounted.state.refresh(), (error: unknown) => apiFailure(error).status === refusal.statusCode)
          await flushSettings()
          assert.equal(mounted.state.authorityKey.value, authority, 'a definitive server refusal can revoke effective authority without changing the context key')
          assert.equal(mounted.state.canRead.value, false)
          assert.equal(mounted.state.canWrite.value, false)
          assert.doesNotMatch(settingsText(mounted), /Valor recusado|Confira os dados/)
          assert.ok(Object.values(mounted.privateForm()!).every(value => value === ''))
        } finally { mounted.dispose() }
      })
    }

    it(`${target} clears already visible feedback immediately when authority changes`, async () => {
      const mounted = settingsHarness(async (_path, options) => { if (options?.method === 'PATCH') throw validationFailure({ [target === 'email' ? `email_settings.${key}` : key]: ['Valor recusado.'] }); return response }, 'owner', ['*'], target)
      try {
        await flushSettings()
        await mounted.submit()
        await flushSettings()
        assert.match(settingsText(mounted), /Valor recusado/)
        mounted.account.value.id++
        await Vue.nextTick()
        assert.doesNotMatch(settingsText(mounted), /Valor recusado|Confira os dados/)
      } finally { mounted.dispose() }
    })

    for (const boundary of ['company', 'account', 'session', 'permission', 'role']) {
      for (const outcome of ['success', 'failure']) {
        it(`${target} discards late ${outcome} and clears feedback on ${boundary} changes`, async () => {
          let resolve!: (value: unknown) => void
          let reject!: (error: unknown) => void
          let attempt = 0
          const mounted = settingsHarness(async (_path, options) => {
            if (options?.method !== 'PATCH') return response
            if (++attempt === 1) throw validationFailure({ [target === 'email' ? `email_settings.${key}` : key]: ['Valor recusado.'] })
            return new Promise((done, fail) => { resolve = done; reject = fail })
          }, 'owner', ['*'], target)
          try {
            await flushSettings()
            await mounted.submit()
            await flushSettings()
            assert.match(settingsText(mounted), /Valor recusado/)
            const request = mounted.submit()
            if (boundary === 'company') mounted.company.value.id++
            else if (boundary === 'account') mounted.account.value.id++
            else if (boundary === 'session') mounted.session.value++
            else if (boundary === 'role') mounted.company.value.role = 'viewer'
            else mounted.company.value.permissions = ['erp.company.settings.show']
            if (outcome === 'success') resolve(response)
            else reject(validationFailure({ [key]: ['Erro obsoleto.'] }))
            await request
            await flushSettings()
            assert.doesNotMatch(settingsText(mounted), /Valor recusado|Erro obsoleto|Confira os dados/)
            assert.deepEqual(mounted.notices.filter(notice => (notice as { color: string }).color === 'success'), [])
            assert.ok(Object.values(mounted.privateForm()!).every(value => value === ''))
            assert.deepEqual(mounted.errors, [])
          } finally { mounted.dispose() }
        })
      }
    }
  }
})
