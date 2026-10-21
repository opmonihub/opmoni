import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { readFileSync } from 'node:fs'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import { apiErrorMessage, apiFieldErrors, apiStatus } from '../app/composables/useApiError.ts'
import type { ErpFiscalSale } from '../shared/types/erpFiscal.ts'
import { buildErpFiscalCertificateUpload, buildErpFiscalDraftSource, createErpFiscalRecoveryRecord } from '../shared/utils/erpFiscal.ts'

const certificate = new File(['pkcs12-private-bytes'], 'issuer.p12', { type: 'application/x-pkcs12' })
const certificateFormFile = new URL('../app/components/erp/ErpFiscalCertificateForm.vue', import.meta.url)
const documentFormFile = new URL('../app/components/erp/ErpFiscalDocumentForm.vue', import.meta.url)
const configurationFormFile = new URL('../app/components/erp/ErpFiscalConfigurationForm.vue', import.meta.url)
const workspaceFormFile = new URL('../app/components/erp/ErpFiscalWorkspace.vue', import.meta.url)
const workspaceComposableFile = new URL('../app/composables/useErpFiscalWorkspace.ts', import.meta.url)

type HostNode = {
  tag: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
  text: string
  value: string
  listeners: Record<string, Array<(event: { target: HostNode }) => void>>
}

function createRenderer() {
  const createNode = (tag: string, text = ''): HostNode => ({ tag, props: {}, children: [], parent: null, text, value: '', listeners: {} })
  const renderer = Vue.createRenderer({
    patchProp(node, key, _old, value) {
      node.props[key] = value
      if (key === 'value') node.value = String(value ?? '')
    },
    insert(node, parent, anchor = null) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = parent
      const at = anchor ? parent.children.indexOf(anchor) : -1
      if (at < 0) parent.children.push(node)
      else parent.children.splice(at, 0, node)
    },
    remove(node) {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = null
    },
    createElement: tag => createNode(tag),
    createText: text => createNode('#text', text),
    createComment: text => createNode('#comment', text),
    setText(node, text) { node.text = text },
    setElementText(node, text) {
      node.text = text
      node.children = []
    },
    parentNode: node => node.parent,
    nextSibling(node) { return node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null }
  })
  return { renderer, createNode }
}

function mountCertificateForm(props: Record<string, unknown>) {
  const source = readFileSync(certificateFormFile, 'utf8')
  const { descriptor } = parse(source, { filename: certificateFormFile.pathname })
  const script = compileScript(descriptor, { id: 'erp-fiscal-certificate-form-test' })
  const compiledScript = ts.transpileModule(script.content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
  })
  const exports = {} as { default: Record<string, unknown> }
  new Function('require', 'exports', 'ref', 'computed', 'reactive', compiledScript.outputText)((name) => {
    if (name === 'vue') return Vue
    throw new Error(`Unexpected SFC import: ${name}`)
  }, exports, Vue.ref, Vue.computed, Vue.reactive)
  const template = compileTemplate({
    source: descriptor.template?.content ?? '',
    filename: certificateFormFile.pathname,
    id: 'erp-fiscal-certificate-template-test',
    compilerOptions: { mode: 'function', hoistStatic: false, bindingMetadata: script.bindings, expressionPlugins: ['typescript'] }
  })
  const compiledTemplate = ts.transpileModule(template.code, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
  })
  const component = exports.default as { render?: (context: unknown, cache: unknown) => unknown }
  component.render = new Function('Vue', compiledTemplate.outputText)(Vue) as typeof component.render
  const { renderer, createNode } = createRenderer()
  const app = renderer.createApp(component as never, props)
  app.component('UForm', Vue.defineComponent({
    inheritAttrs: false,
    emits: ['submit'],
    setup(_props, { attrs, slots, emit }) {
      return () => Vue.h('form', {
        ...attrs,
        onSubmit: (event: { preventDefault: () => void }) => {
          event.preventDefault()
          emit('submit', event)
        }
      }, slots.default?.())
    }
  }))
  app.component('UFormField', Vue.defineComponent({
    props: ['label', 'name', 'error'],
    setup(props, { attrs, slots }) {
      return () => Vue.h('label', { ...attrs, 'data-field': props.name }, [Vue.h('span', props.label), slots.default?.(), props.error ? Vue.h('span', { role: 'alert' }, String(props.error)) : null])
    }
  }))
  app.component('UInput', Vue.defineComponent({
    props: ['modelValue', 'type', 'name', 'disabled', 'accept', 'required'],
    emits: ['update:modelValue'],
    setup(inputProps, { attrs, emit }) {
      return () => Vue.h('input', {
        ...attrs,
        type: inputProps.type,
        name: inputProps.name,
        disabled: inputProps.disabled,
        accept: inputProps.accept,
        required: inputProps.required,
        value: inputProps.modelValue,
        onInput: (event: { target: { value: string } }) => emit('update:modelValue', event.target.value)
      })
    }
  }))
  app.component('UFileUpload', Vue.defineComponent({
    props: ['modelValue', 'accept', 'disabled', 'multiple'],
    emits: ['update:modelValue'],
    setup(fileProps, { attrs, emit }) {
      return () => Vue.h('input', {
        ...attrs,
        type: 'file',
        accept: fileProps.accept,
        disabled: fileProps.disabled,
        multiple: fileProps.multiple,
        onChange: (event: { target: { files: FileList | File[] | null } }) => emit('update:modelValue', event.target.files?.[0] ?? null)
      })
    }
  }))
  app.component('UButton', Vue.defineComponent({
    props: ['type', 'disabled', 'loading'],
    emits: ['click'],
    setup(buttonProps, { attrs, slots, emit }) {
      return () => Vue.h('button', { ...attrs, type: buttonProps.type ?? 'button', disabled: buttonProps.disabled, onClick: (event: unknown) => emit('click', event) }, slots.default?.())
    }
  }))
  const root = createNode('root')
  app.mount(root as never)
  return { app, root, props }
}

function mountDocumentForm(props: Record<string, unknown>) {
  const source = readFileSync(documentFormFile, 'utf8')
  const { descriptor } = parse(source, { filename: documentFormFile.pathname })
  const script = compileScript(descriptor, { id: 'erp-fiscal-document-form-test' })
  const compiledScript = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
  const exports = {} as { default: Record<string, unknown> }
  const globals = { ref: Vue.ref, computed: Vue.computed, reactive: Vue.reactive, watch: Vue.watch }
  new Function('require', 'exports', ...Object.keys(globals), compiledScript.outputText)((name: string) => {
    if (name === 'vue') return Vue
    if (name === '#shared/utils/erpFiscal') return { buildErpFiscalDraftSource }
    throw new Error(`Unexpected document SFC import: ${name}`)
  }, exports, ...Object.values(globals))
  const template = compileTemplate({ source: descriptor.template?.content ?? '', filename: documentFormFile.pathname, id: 'erp-fiscal-document-template-test', compilerOptions: { mode: 'function', hoistStatic: false, bindingMetadata: script.bindings, expressionPlugins: ['typescript'] } })
  const compiledTemplate = ts.transpileModule(template.code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
  const component = exports.default as { render?: (context: unknown, cache: unknown) => unknown }
  component.render = new Function('Vue', compiledTemplate.outputText)(Vue) as typeof component.render
  const { renderer, createNode } = createRenderer()
  const app = renderer.createApp(component as never, props)
  app.component('UForm', Vue.defineComponent({
    inheritAttrs: false,
    emits: ['submit'],
    setup(_props, { attrs, slots, emit }) {
      return () => Vue.h('form', {
        ...attrs,
        onSubmit: (event: { preventDefault: () => void }) => {
          event.preventDefault()
          emit('submit', event)
        }
      }, slots.default?.())
    }
  }))
  app.component('UFormField', Vue.defineComponent({
    props: ['label', 'name', 'error'],
    setup(field, { attrs, slots }) {
      Vue.provide('erp-fiscal-test-field', field.name)
      return () => Vue.h('label', { ...attrs, 'data-field': field.name }, [Vue.h('span', String(field.label ?? '')), slots.default?.(), field.error ? Vue.h('span', { role: 'alert' }, String(field.error)) : null])
    }
  }))
  app.component('UInput', fiscalTestInput())
  app.component('UTextarea', fiscalTestInput('textarea'))
  app.component('USelect', Vue.defineComponent({
    props: ['modelValue', 'items', 'disabled'],
    emits: ['update:modelValue'],
    setup(select, { emit }) {
      const fieldName = Vue.inject<string>('erp-fiscal-test-field', '')
      return () => Vue.h('select', {
        'data-field': fieldName,
        'value': select.modelValue == null ? '' : String(select.modelValue),
        'disabled': select.disabled,
        'onChange': (event: { target: { value: string } }) => {
          const option = (select.items as Array<{ value: unknown }>).find(item => String(item.value) === event.target.value)
          emit('update:modelValue', option?.value ?? event.target.value)
        }
      }, (select.items as Array<{ label: string, value: unknown }>).map(item => Vue.h('option', { value: String(item.value) }, item.label)))
    }
  }))
  for (const name of ['UAlert', 'UBadge']) {
    app.component(name, Vue.defineComponent({
      setup(_props, { slots }) {
        return () => slots.default?.() ?? null
      }
    }))
  }
  app.component('UButton', Vue.defineComponent({
    props: ['type', 'disabled', 'loading'],
    setup(button, { attrs, slots }) {
      return () => Vue.h('button', { ...attrs, type: button.type ?? 'button', disabled: button.disabled }, slots.default?.())
    }
  }))
  const root = createNode('root')
  app.mount(root as never)
  return { app, root, props }
}

function mountConfigurationForm(props: Record<string, unknown>) {
  const source = readFileSync(configurationFormFile, 'utf8')
  const { descriptor } = parse(source, { filename: configurationFormFile.pathname })
  const script = compileScript(descriptor, { id: 'erp-fiscal-configuration-form-test' })
  const compiledScript = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
  const exports = {} as { default: Record<string, unknown> }
  const globals = { ref: Vue.ref, computed: Vue.computed, reactive: Vue.reactive, watch: Vue.watch }
  new Function('require', 'exports', ...Object.keys(globals), compiledScript.outputText)((name: string) => {
    if (name === 'vue') return Vue
    throw new Error(`Unexpected configuration SFC import: ${name}`)
  }, exports, ...Object.values(globals))
  const template = compileTemplate({ source: descriptor.template?.content ?? '', filename: configurationFormFile.pathname, id: 'erp-fiscal-configuration-template-test', compilerOptions: { mode: 'function', hoistStatic: false, bindingMetadata: script.bindings, expressionPlugins: ['typescript'] } })
  const compiledTemplate = ts.transpileModule(template.code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
  const component = exports.default as { render?: (context: unknown, cache: unknown) => unknown }
  component.render = new Function('Vue', compiledTemplate.outputText)(Vue) as typeof component.render
  const { renderer, createNode } = createRenderer()
  const app = renderer.createApp(component as never, props)
  app.component('UForm', Vue.defineComponent({
    inheritAttrs: false,
    emits: ['submit'],
    setup(_props, { attrs, slots, emit }) {
      return () => Vue.h('form', {
        ...attrs,
        onSubmit: (event: { preventDefault: () => void }) => {
          event.preventDefault()
          emit('submit', event)
        }
      }, slots.default?.())
    }
  }))
  app.component('UFormField', Vue.defineComponent({
    props: ['label', 'name', 'error'],
    setup(field, { attrs, slots }) {
      Vue.provide('erp-fiscal-test-field', field.name)
      return () => Vue.h('label', { ...attrs, 'data-field': field.name }, [Vue.h('span', String(field.label ?? '')), slots.default?.()])
    }
  }))
  app.component('UInput', fiscalTestInput())
  app.component('USelect', Vue.defineComponent({
    props: ['modelValue', 'items', 'disabled'],
    emits: ['update:modelValue'],
    setup(select, { emit }) {
      const fieldName = Vue.inject<string>('erp-fiscal-test-field', '')
      return () => Vue.h('select', {
        'data-field': fieldName,
        'value': select.modelValue == null ? '' : String(select.modelValue),
        'disabled': select.disabled,
        'onChange': (event: { target: { value: string } }) => {
          const option = (select.items as Array<{ value: unknown }>).find(item => String(item.value) === event.target.value)
          emit('update:modelValue', option?.value ?? event.target.value)
        }
      }, (select.items as Array<{ label: string, value: unknown }>).map(item => Vue.h('option', { value: String(item.value) }, item.label)))
    }
  }))
  app.component('UCheckbox', Vue.defineComponent({
    props: ['modelValue', 'label', 'disabled'],
    emits: ['update:modelValue'],
    setup(control, { emit }) {
      return () => Vue.h('input', {
        'type': 'checkbox', 'checked': control.modelValue, 'disabled': control.disabled,
        'aria-label': control.label,
        'onChange': (event: { target: { checked: boolean } }) => emit('update:modelValue', event.target.checked)
      })
    }
  }))
  for (const name of ['UAlert', 'UBadge']) {
    app.component(name, Vue.defineComponent({
      setup(_props, { slots }) {
        return () => slots.default?.() ?? null
      }
    }))
  }
  app.component('UButton', Vue.defineComponent({
    props: ['type', 'disabled', 'loading'],
    setup(button, { attrs, slots }) {
      return () => Vue.h('button', { ...attrs, type: button.type ?? 'button', disabled: button.disabled }, slots.default?.())
    }
  }))
  const root = createNode('root')
  app.mount(root as never)
  return { app, root }
}

function fiscalTestInput(tag = 'input') {
  return Vue.defineComponent({
    props: ['modelValue', 'type', 'name', 'disabled', 'maxlength', 'inputmode'],
    emits: ['update:modelValue'],
    setup(input, { attrs, emit }) {
      const fieldName = Vue.inject<string>('erp-fiscal-test-field', '')
      return () => Vue.h(tag, {
        ...attrs,
        'data-field': fieldName,
        'type': input.type,
        'name': input.name,
        'disabled': input.disabled,
        'maxlength': input.maxlength,
        'inputmode': input.inputmode,
        'value': input.modelValue ?? '',
        'onInput': (event: { target: { value: string } }) => emit('update:modelValue', event.target.value)
      })
    }
  })
}

function setFiscalField(root: HostNode, name: string, value: string) {
  const field = findNode(root, node => node.props['data-field'] === name)
  assert.ok(field, `mounted fiscal form has ${name}`)
  const control = findNode(field, node => ['input', 'select', 'textarea'].includes(node.tag))
  assert.ok(control, `mounted fiscal field ${name} has a control`)
  if (control.tag === 'select') (control.props.onChange as (event: { target: { value: string } }) => void)({ target: { value } })
  else (control.props.onInput as (event: { target: { value: string } }) => void)({ target: { value } })
}

function findNode(root: HostNode, predicate: (node: HostNode) => boolean): HostNode | undefined {
  if (predicate(root)) return root
  for (const child of root.children) {
    const result = findNode(child, predicate)
    if (result) return result
  }
}

function findNodes(root: HostNode, predicate: (node: HostNode) => boolean): HostNode[] {
  return [
    ...(predicate(root) ? [root] : []),
    ...root.children.flatMap(child => findNodes(child, predicate))
  ]
}

function textContent(node: HostNode): string {
  return `${node.text}${node.children.map(textContent).join('')}`
}

function mountWorkspaceSfc(workspaceValue: Record<string, unknown>, identity: { value: { id: number, current_company: { id: number, permissions: string[] } } }) {
  const source = readFileSync(workspaceFormFile, 'utf8')
  const { descriptor } = parse(source, { filename: workspaceFormFile.pathname })
  const script = compileScript(descriptor, { id: 'erp-fiscal-workspace-test' })
  const compiledScript = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
  const contextKey = Vue.computed(() => `${identity.value.id}:${identity.value.current_company.id}:${identity.value.current_company.permissions.join(',')}`)
  const globals = {
    reactive: Vue.reactive, ref: Vue.ref, computed: Vue.computed, watch: Vue.watch, onMounted: Vue.onMounted,
    useErpFiscalWorkspace: () => workspaceValue,
    useErpAdministrationContext: () => {
      const generation = Vue.ref(0)
      Vue.watch(contextKey, () => generation.value++, { flush: 'sync' })
      return {
        key: contextKey,
        capture: () => ({ key: contextKey.value, generation: generation.value }),
        isCurrent: (token: { key: string, generation: number }) => token.key === contextKey.value && token.generation === generation.value
      }
    }
  }
  const exports = {} as { default: Record<string, unknown> }
  new Function('require', 'exports', ...Object.keys(globals), compiledScript.outputText)((name: string) => {
    if (name === 'vue') return Vue
    throw new Error(`Unexpected workspace SFC import: ${name}`)
  }, exports, ...Object.values(globals))
  const template = compileTemplate({ source: descriptor.template?.content ?? '', filename: workspaceFormFile.pathname, id: 'erp-fiscal-workspace-template-test', compilerOptions: { mode: 'function', hoistStatic: false, bindingMetadata: script.bindings, expressionPlugins: ['typescript'] } })
  const compiledTemplate = ts.transpileModule(template.code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
  const component = exports.default as { render?: (context: unknown, cache: unknown) => unknown }
  component.render = new Function('Vue', compiledTemplate.outputText)(Vue) as typeof component.render
  const { renderer, createNode } = createRenderer()
  const app = renderer.createApp(component as never)
  app.component('ErpCatalogPageShell', Vue.defineComponent({
    setup(_props, { slots }) { return () => Vue.h('main', [slots.actions?.(), slots.toolbar?.(), slots.default?.()]) }
  }))
  app.component('ErpFiscalDocumentDetail', Vue.defineComponent({
    props: ['document'], emits: ['transmit'],
    setup(props, { emit }) { return () => Vue.h('button', { 'data-action': 'request-transmit', 'onClick': () => emit('transmit', props.document) }, 'Transmitir') }
  }))
  app.component('UModal', Vue.defineComponent({
    props: ['open', 'title'], emits: ['update:open'],
    setup(modal, { slots }) { return () => modal.open ? Vue.h('section', { 'data-modal': modal.title }, [slots.body?.(), slots.footer?.()]) : null }
  }))
  app.component('UButton', Vue.defineComponent({
    props: ['disabled', 'loading'],
    setup(button, { attrs, slots }) { return () => Vue.h('button', { ...attrs, disabled: button.disabled }, slots.default?.()) }
  }))
  app.component('USelect', Vue.defineComponent({
    setup() {
      return () => Vue.h('select')
    }
  }))
  for (const name of ['UAlert', 'UBadge', 'UCard', 'ErpFiscalDocumentForm', 'ErpFiscalEventForm', 'ErpFiscalConfigurationForm', 'ErpFiscalCertificateForm']) {
    app.component(name, Vue.defineComponent({
      setup(_props, { slots }) {
        return () => Vue.h('div', slots.default?.())
      }
    }))
  }
  const root = createNode('root')
  app.mount(root as never)
  return { app, root }
}

function loadWorkspaceComposable(api: (path: string, options?: Record<string, unknown>) => Promise<unknown>, context: { actorId: number, companyId: number | null, grants: string[], accessKind?: 'direct' | 'delegated', companyRole?: string, accountRole?: string, accountId?: number, delegatedOfficeId?: number | null }) {
  const source = readFileSync(workspaceComposableFile, 'utf8')
  const output = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
  }).outputText
  const identity = Vue.ref({
    id: context.actorId,
    current_company: context.companyId === null
      ? null
      : {
          id: context.companyId,
          status: context.companyId === null ? null : 'active',
          access_kind: context.companyId === null ? null : context.accessKind ?? 'direct',
          role: context.companyId === null ? null : context.companyRole ?? (context.accessKind === 'delegated' ? 'delegated' : 'owner'),
          permissions: context.grants,
          delegated_office_id: context.accessKind === 'delegated' ? context.delegatedOfficeId ?? 3 : null
        },
    current_account: { id: context.accountId ?? 3, status: 'active', role: context.accountRole ?? 'admin' }
  })
  const contextKey = Vue.computed(() => `${identity.value.id}:${identity.value.current_company?.id ?? 'none'}:${identity.value.current_company?.permissions.join(',') ?? ''}`)
  const useContext = () => {
    const generation = Vue.ref(0)
    Vue.watch(contextKey, () => generation.value++, { flush: 'sync' })
    return {
      key: contextKey,
      capture: () => ({ key: contextKey.value, generation: generation.value }),
      isCurrent: (token: { key: string, generation: number }) => token.key === contextKey.value && token.generation === generation.value
    }
  }
  const globals = {
    computed: Vue.computed,
    ref: Vue.ref,
    reactive: Vue.reactive,
    watch: Vue.watch,
    onScopeDispose: Vue.onScopeDispose,
    useAccessApi: () => ({ api }),
    useAuth: () => ({ user: Vue.computed(() => identity.value), currentCompany: Vue.computed(() => identity.value.current_company), currentAccount: Vue.computed(() => identity.value.current_account) }),
    useErpAdministrationContext: useContext,
    useState: (_key: string, initializer: () => unknown) => Vue.ref(initializer())
  }
  const exports = {} as { useErpFiscalWorkspace: () => Record<string, unknown> }
  new Function('require', 'exports', ...Object.keys(globals), output)(
    (name: string) => {
      if (name === 'vue') return Vue
      if (name === './useAccessApi') return { useAccessApi: () => ({ api }) }
      if (name === './useErpAdministrationContext') return { useErpAdministrationContext: useContext }
      if (name === './useApiError') return { apiErrorMessage, apiFieldErrors, apiStatus }
      if (name === '../../shared/utils/erpFiscal' || name === '#shared/utils/erpFiscal') return { buildErpFiscalCertificateUpload, createErpFiscalRecoveryRecord, erpFiscalRecoveryStorageKey: (actorId: number, companyId: number) => `opmoni:erp-fiscal:recovery:v1:${actorId}:${companyId}`, fiscalApiFieldErrors: (error: unknown) => {
        const details = error as { data?: { errors?: Record<string, string[]> } }
        return Object.fromEntries(Object.entries(details.data?.errors ?? {}).map(([key, value]) => [key, value[0]]))
      }, parseErpFiscalRecoveryRecord: (raw: string | null, actorId: number, companyId: number) => {
        if (!raw) return null
        const value = JSON.parse(raw) as { actorId: number, companyId: number, path: string }
        return value.actorId === actorId && value.companyId === companyId && value.path.startsWith('/erp/fiscal/') ? value : null
      } }
      if (name === '~/utils/erpModulesNav') return { erpCompanyHasPermission: (grants: string[], permission: string) => grants.includes('*') || grants.includes(permission) || grants.includes(`${permission.slice(0, permission.lastIndexOf('.'))}.*`) }
      throw new Error(`Unexpected fiscal composable import: ${name}`)
    },
    exports,
    ...Object.values(globals)
  )
  const scope = Vue.effectScope()
  const workspace = Vue.proxyRefs(scope.run(() => exports.useErpFiscalWorkspace())!)
  return { workspace, identity, close: () => scope.stop() }
}

function installSessionStorage(t: { after: (callback: () => void) => void }) {
  const memory = new Map<string, string>()
  const storage = {
    get length() { return memory.size },
    key(index: number) { return [...memory.keys()][index] ?? null },
    getItem(key: string) { return memory.get(key) ?? null },
    setItem(key: string, value: string) { memory.set(key, value) },
    removeItem(key: string) { memory.delete(key) },
    clear() { memory.clear() }
  }
  Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: storage })
  t.after(() => {
    Reflect.deleteProperty(globalThis, 'sessionStorage')
  })
  return memory
}

function fiscalSale(): ErpFiscalSale {
  return {
    id: 73,
    status: 'posted',
    erp_establishment_id: 6,
    commercial_snapshot: {
      party: { tax_id: '11222333000181', name: 'Destinatário de teste' },
      totals: { total_amount: '100.00' },
      lines: [{
        id: 3,
        quantity: '1.0000',
        unit_price: '100.0000000000',
        gross_amount: '100.0000',
        product_snapshot: {
          product: { name: 'Produto de teste', sku: 'SKU-1', ncm: '12345678', origin_code: '0', icms_cst: '00', pis_cst: '07', cofins_cst: '07' },
          variant: { sku: 'SKU-1' },
          unit: { short_name: 'UN' },
          operation_nature: { cfop: '5102' }
        }
      }]
    }
  }
}

function fiscalConfiguration(model: '55' | '65' | '57' | '58', qrMode: 'online' | 'offline' = 'online') {
  return {
    environment: 'homologation',
    models: {
      [model]: { readiness: { status: 'locally_configured', blockers: [] }, nfce_qr: { version: 3, mode: qrMode, consultation_url: 'https://test.invalid/consulta' } }
    },
    release_gates: { provider: 'unavailable', production_transmission_enabled: false, production_blocker: 'provider_unavailable' }
  }
}

async function submitDocumentSfc(model: '55' | '65' | '57' | '58', fields: Record<string, string>, options: { qrMode?: 'online' | 'offline', originalModel?: '55' | '57', originalAccessKey?: string, selectedReturn?: ErpFiscalSale | null, returnRows?: ErpFiscalSale[], selectedSale?: ErpFiscalSale | null, fieldErrors?: Record<string, string> } = {}) {
  const emitted: Array<Record<string, unknown>> = []
  const original = { id: 91, model: options.originalModel ?? '55', environment: 'homologation' as const, erp_establishment_id: 6, status: 'authorized' as const, access_key: options.originalAccessKey ?? '35261011222333000181550010000001231123456783', artifact_ready: true, claims_provider_authorization: true }
  const mounted = mountDocumentForm({
    establishmentId: 6,
    configuration: fiscalConfiguration(model, options.qrMode),
    model,
    sales: [fiscalSale()],
    selectedSale: options.selectedSale === undefined ? (['55', '65'].includes(model) ? fiscalSale() : null) : options.selectedSale,
    returns: options.returnRows ?? [],
    selectedReturn: options.selectedReturn ?? null,
    originalDocuments: [original],
    fieldErrors: options.fieldErrors ?? {},
    canCreate: true,
    busy: false,
    onCreate: (body: Record<string, unknown>) => emitted.push(body)
  })
  if (fields.document_kind) {
    setFiscalField(mounted.root, 'document_kind', fields.document_kind)
    await Vue.nextTick()
  }
  for (const [field, value] of Object.entries(fields)) {
    if (field === 'document_kind' || field === 'identity.purpose') continue
    setFiscalField(mounted.root, field, value)
    await Vue.nextTick()
  }
  const form = findNode(mounted.root, node => node.tag === 'form')
  assert.ok(form)
  ;(form.props.onSubmit as (event: { preventDefault: () => void }) => void)({ preventDefault() {} })
  return { app: mounted.app, root: mounted.root, emitted, props: mounted.props }
}

function nfeSfcFacts(model: '55' | '65', offline = false): Record<string, string> {
  const facts: Record<string, string> = {
    'identity.operation_type': '1', 'identity.destination_type': '1', 'identity.purpose': '1',
    'identity.final_consumer': '1', 'identity.presence_indicator': model === '65' ? '1' : '0', 'identity.print_type': model === '65' ? '4' : '1',
    'recipient.state_registration_indicator': '9', 'recipient.address.street': 'Rua Dois', 'recipient.address.number': '20',
    'recipient.address.district': 'Centro', 'recipient.address.city_code': '3550308', 'recipient.address.city': 'Sao Paulo',
    'recipient.address.postal_code': '01002000', 'recipient.address.uf': 'SP', 'items.0.description': 'Produto de teste', 'items.0.ind_total': '1',
    'items.0.tax.icms_base': '100.00', 'items.0.tax.icms_rate': '18.00', 'items.0.tax.icms_value': '18.00',
    'items.0.tax.pis_value': '0.00', 'items.0.tax.cofins_value': '0.00', 'freight.modality': '9',
    'payment.0.method': '01', 'payment.0.amount': '100.00'
  }
  for (const key of ['products', 'invoice', 'icms_base']) facts[`source.totals.${key}`] = '100.00'
  for (const key of ['icms', 'taxes_total']) facts[`source.totals.${key}`] = '18.00'
  for (const key of ['icms_desonerated', 'fcp', 'st_base', 'st', 'fcp_st', 'fcp_st_retained', 'freight', 'insurance', 'discount', 'ii', 'ipi', 'ipi_returned', 'pis', 'cofins', 'other']) facts[`source.totals.${key}`] = '0.00'
  if (offline) {
    facts['identity.contingency_started_at'] = '2026-10-21T10:00'
    facts['identity.contingency_justification'] = 'Indisponibilidade de comunicação com o provedor fiscal.'
  }
  return facts
}

function cteSfcFacts(): Record<string, string> {
  const facts: Record<string, string> = {
    'transport_service.service_type': '0', 'transport_service.emission_type': '1', 'transport_service.operation_nature': 'Prestacao de servico de transporte',
    'transport_service.cfop': '5353', 'transport_service.rntrc': '12345678', 'transport_service.service_value': '250.00',
    'transport_service.received_value': '250.00', 'transport_service.tax_cst': '00', 'transport_service.tax_base': '250.00',
    'transport_service.tax_rate': '12.00', 'transport_service.tax_value': '30.00', 'transport_service.cargo_description': 'Mercadorias diversas',
    'transport_service.cargo_measure_type': 'PESO BRUTO', 'transport_service.cargo_unit': '01', 'transport_service.cargo_quantity': '1.0000',
    'transport_service.cargo_value': '250.00', 'sender_cnpj': '11222333000181', 'sender_name': 'Remetente LTDA',
    'sender_state_registration': '110042490114', 'sender_state_registration_indicator': '1', 'sender_street': 'Rua Um', 'sender_number': '10',
    'sender_district': 'Centro', 'sender_city_code': '3550308', 'sender_city': 'Sao Paulo', 'sender_postal_code': '01001000', 'sender_uf': 'SP',
    'receiver_cnpj': '11222333000181', 'receiver_name': 'Destinatario LTDA', 'receiver_state_registration': '110042490114',
    'receiver_state_registration_indicator': '1', 'receiver_street': 'Rua Dois', 'receiver_number': '20', 'receiver_district': 'Centro',
    'receiver_city_code': '3304557', 'receiver_city': 'Rio de Janeiro', 'receiver_postal_code': '20000000', 'receiver_uf': 'RJ',
    'taker_role': '0', 'origin_uf': 'SP', 'origin_city_code': '3550308', 'origin_city': 'Sao Paulo', 'destination_uf': 'RJ',
    'destination_city_code': '3304557', 'destination_city': 'Rio de Janeiro', 'pickup': '0'
  }
  return facts
}

function mdfeSfcFacts(externalReference = true): Record<string, string> {
  return {
    'transport_manifest.manifest_emission_type': '1', 'transport_manifest.manifest_modal': '1',
    'transport_manifest.manifest_issuer_type': '2', 'transport_manifest.manifest_rntrc': '12345678',
    'transport_manifest.manifest_route_ufs': 'SP,RJ', 'transport_manifest.manifest_load_municipality_name': 'Sao Paulo',
    'transport_manifest.manifest_load_municipality_code': '3550308', 'transport_manifest.manifest_unload_municipality_name': 'Rio de Janeiro',
    'transport_manifest.manifest_unload_municipality_code': '3304557', 'transport_manifest.vehicle_plate': 'ABC1D23',
    'transport_manifest.vehicle_renavam': '12345678901', 'transport_manifest.vehicle_registry': '12345678',
    'transport_manifest.vehicle_tare': '1000', 'transport_manifest.vehicle_capacity': '10000',
    'transport_manifest.vehicle_road_type': '02', 'transport_manifest.vehicle_trailer_type': '00',
    'transport_manifest.vehicle_uf': 'SP', 'transport_manifest.driver_name': 'Motorista teste',
    'transport_manifest.driver_cpf': '12345678909', 'transport_manifest.cargo_product_description': 'Mercadorias diversas',
    'transport_manifest.cargo_product_type': '01', 'transport_manifest.cargo_ncm': '12345678',
    'transport_manifest.cargo_total_value': '250.00', 'transport_manifest.cargo_unit_manifest': '01',
    'transport_manifest.cargo_quantity_manifest': '1.0000', 'transport_manifest.cte_total': '0',
    'transport_manifest.nfe_total': '1', 'transport_manifest.mdfe_total': '0',
    ...(externalReference ? { 'transport_manifest.external_model': '55', 'transport_manifest.external_access_key': '35261011222333000181550010000001231123456783' } : { 'transport_manifest.document': '91' })
  }
}

describe('ERP fiscal typed request helpers', () => {
  it('emits complete human-entered NF-e and NFC-e online source facts from the mounted form', async () => {
    for (const model of ['55', '65'] as const) {
      const mounted = await submitDocumentSfc(model, nfeSfcFacts(model))
      assert.equal(mounted.emitted.length, 1, `model ${model} form emits only after required facts are entered`)
      const body = mounted.emitted[0]!
      assert.equal(body.model, model)
      assert.equal(body.document_kind, 'posted_sale')
      assert.equal((body.source as Record<string, unknown>).identity && ((body.source as Record<string, Record<string, unknown>>).identity.operation_type), 1)
      assert.equal((body.source as Record<string, Array<Record<string, unknown>>>).items[0]?.gross_total, '100.00')
      mounted.app.unmount()
    }
  })

  it('emits a complete NFC-e offline source with the entered contingency time and reason', async () => {
    const mounted = await submitDocumentSfc('65', nfeSfcFacts('65', true), { qrMode: 'offline' })

    assert.equal(mounted.emitted.length, 1)
    const source = mounted.emitted[0]?.source as Record<string, Record<string, unknown>>
    assert.equal(source.identity.contingency_started_at, '2026-10-21T10:00')
    assert.equal(source.identity.contingency_justification, 'Indisponibilidade de comunicação com o provedor fiscal.')
    mounted.app.unmount()
  })

  it('emits complete typed CT-e and MDF-e facts from their native mounted forms', async () => {
    const cte = await submitDocumentSfc('57', cteSfcFacts())
    assert.equal(cte.emitted.length, 1)
    assert.equal(cte.emitted[0]?.document_kind, 'transport_service')
    assert.equal((cte.emitted[0]?.source as Record<string, Record<string, unknown>>).transport_service.rntrc, '12345678')
    const cargoDescription = findNode(cte.root, node => node.tag === 'input' && node.props['data-field'] === 'transport_service.cargo_description')
    assert.equal(cargoDescription?.value, 'Mercadorias diversas', 'free-text CT-e facts remain visible after the form update tick')
    const serviceType = findNode(cte.root, node => node.tag === 'select' && node.props['data-field'] === 'transport_service.service_type')
    assert.equal(serviceType?.value, '0', 'numeric enum zero remains selected after the form update tick')
    cte.app.unmount()

    const mdfe = await submitDocumentSfc('58', mdfeSfcFacts())
    assert.equal(mdfe.emitted.length, 1)
    assert.equal(mdfe.emitted[0]?.document_kind, 'transport_manifest')
    const manifest = (mdfe.emitted[0]?.source as Record<string, Record<string, unknown>>).transport_manifest
    assert.equal(manifest.rntrc, '12345678')
    assert.deepEqual(manifest.documents, [{ model: '55', access_key: '35261011222333000181550010000001231123456783', municipality_code: '3304557' }])
    mdfe.app.unmount()
  })

  it('emits CT-e complement and substitution references from the selected authorized CT-e', async () => {
    const originalKey = '35261011222333000181570010000001231123456780'
    for (const [kind, cteType] of [['complement', 1], ['substitution', 3]] as const) {
      const mounted = await submitDocumentSfc('57', { ...cteSfcFacts(), document_kind: kind, source_document_id: '91' }, { originalModel: '57', originalAccessKey: originalKey })
      assert.equal(mounted.emitted.length, 1, `${kind} emits after selecting its authorized source`)
      const body = mounted.emitted[0]!
      const service = (body.source as Record<string, Record<string, unknown>>).transport_service
      assert.equal(body.source_document_id, 91)
      assert.equal(body.document_kind, kind)
      assert.equal(service.cte_type, cteType)
      assert.equal(service.original_cte_access_key, originalKey)
      mounted.app.unmount()
    }
  })

  it('emits an NF-e return from the selected posted return line and frozen source line', async () => {
    const returnLine = {
      id: 901,
      source_line_id: 801,
      quantity: '1.0000',
      subtotal_amount: '15.50',
      source_snapshot: { accepted: { actor_id: 7 }, line: { id: 801, unit_price: '15.5000', product_snapshot: { product: { name: 'Café', ncm: '09012100', origin_code: '0', icms_cst: '00', pis_cst: '07', cofins_cst: '07' }, variant: { sku: 'CAF-001' }, unit: { short_name: 'UN' } } } }
    }
    const returnDocument = { id: 88, status: 'posted', erp_establishment_id: 6, erp_sale_id: 73, commercial_snapshot: { party: { tax_id: '11222333000181', name: 'Destinatário de teste' } }, lines: [returnLine] } as unknown as ErpFiscalSale
    const sale = { ...fiscalSale(), commercial_snapshot: { ...fiscalSale().commercial_snapshot!, lines: [{ ...fiscalSale().commercial_snapshot!.lines![0]!, id: 801 }] } }
    const fields = { ...nfeSfcFacts('55'), 'document_kind': 'return', 'source_document_id': '91', 'operation_nature.cfop': '1202' }
    fields['identity.operation_type'] = '0'
    fields['items.0.tax.icms_base'] = '15.50'
    fields['items.0.tax.icms_rate'] = '18.00'
    fields['items.0.tax.icms_value'] = '2.79'
    fields['source.totals.products'] = '15.50'
    fields['source.totals.invoice'] = '15.50'
    fields['source.totals.icms_base'] = '15.50'
    fields['source.totals.icms'] = '2.79'
    fields['source.totals.taxes_total'] = '2.79'
    fields['payment.0.amount'] = '15.50'
    const mounted = await submitDocumentSfc('55', fields, { selectedSale: sale, selectedReturn: returnDocument, returnRows: [returnDocument] })

    assert.equal(mounted.emitted.length, 1)
    const body = mounted.emitted[0]!
    const source = body.source as Record<string, unknown>
    const item = (source.items as Array<Record<string, unknown>>)[0]!
    assert.equal(body.document_kind, 'return')
    assert.equal(body.erp_sale_return_id, 88)
    assert.equal(body.source_document_id, 91)
    assert.equal(body.erp_sale_id, undefined)
    assert.equal((source.identity as Record<string, unknown>).purpose, 4)
    assert.equal(item.source_return_line_id, 901)
    assert.equal(item.source_line_id, 801)
    assert.equal(item.cfop, '1202')
    assert.equal(item.gross_total, '15.50')
    mounted.app.unmount()
  })

  it('keeps entered document facts visible and renders mapped field errors after a mounted-form 422', async (t) => {
    installSessionStorage(t)
    const fieldErrors = Vue.reactive<Record<string, string>>({})
    const mounted = await submitDocumentSfc('55', nfeSfcFacts('55'), { fieldErrors })
    const cityCode = findNode(mounted.root, node => node.tag === 'input' && node.props['data-field'] === 'recipient.address.city_code')
    assert.equal(cityCode?.value, '3550308')

    const operation = loadWorkspaceComposable(async () => {
      throw { statusCode: 422, data: { errors: { 'source.recipient.address.city_code': ['Código municipal inválido.'] } } }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(operation.close)
    const body = mounted.emitted[0]!
    operation.workspace.documentForm = body
    await assert.rejects(operation.workspace.createDocumentDraft(body))
    Object.assign(fieldErrors, operation.workspace.fieldErrors)
    await Vue.nextTick()

    assert.equal(cityCode?.value, '3550308', 'a rejected draft keeps the operator-entered municipality code')
    const field = findNode(mounted.root, node => node.props['data-field'] === 'recipient.address.city_code')
    assert.equal(textContent(field!), 'Código IBGE do municípioCódigo municipal inválido.')
    mounted.app.unmount()
  })

  it('saves the complete typed MDF-e configuration with the selected supported issuer type', async () => {
    const emitted: Array<Record<string, unknown>> = []
    const configuration = {
      establishment: { id: 6, name: 'Matriz', cnpj: '30288513000100', status: 'active' },
      environment: 'homologation', revision: 2, revision_id: 8,
      issuer: { tax_regime: 3, state_registration: '110042490114', uf: 'SP', address: { street: 'Rua Um', number: '10', district: 'Centro', city_code: '3550308', city_name: 'Sao Paulo', zip_code: '01001000' } },
      models: {},
      release_gates: { provider: 'unavailable', production_transmission_enabled: false, production_blocker: 'provider_unavailable' }
    }
    const mounted = mountConfigurationForm({ configuration, canWrite: true, operationNatures: [], onSave: (body: Record<string, unknown>) => emitted.push(body) })
    const modelCheckboxes = findNodes(mounted.root, node => node.tag === 'input' && node.props.type === 'checkbox')
    assert.equal(modelCheckboxes.length, 4)
    ;(modelCheckboxes[3]!.props.onChange as (event: { target: { checked: boolean } }) => void)({ target: { checked: true } })
    await Vue.nextTick()

    const fields: Record<string, string> = {
      'models.58.series': '1', 'models.58.next_number': '123',
      'models.58.transport.manifest_emission_type': '1', 'models.58.transport.manifest_issuer_type': '2',
      'models.58.transport.manifest_rntrc': '12345678', 'models.58.transport.manifest_route_ufs': 'SP,RJ',
      'models.58.transport.load_municipality': 'Sao Paulo', 'models.58.transport.load_municipality_code': '3550308',
      'models.58.transport.unload_municipality': 'Rio de Janeiro', 'models.58.transport.unload_municipality_code': '3304557',
      'models.58.transport.vehicle_plate': 'ABC1D23', 'models.58.transport.vehicle_renavam': '12345678901',
      'models.58.transport.vehicle_registry': '12345678', 'models.58.transport.vehicle_tare': '1000',
      'models.58.transport.vehicle_capacity': '10000', 'models.58.transport.vehicle_road_type': '02',
      'models.58.transport.vehicle_trailer_type': '00', 'models.58.transport.vehicle_uf': 'SP',
      'models.58.transport.driver_name': 'Motorista teste', 'models.58.transport.driver_cpf': '12345678909',
      'models.58.transport.manifest_document_model': '55', 'models.58.transport.manifest_access_key': '35261011222333000181550010000001231123456783',
      'models.58.transport.cargo_product_description': 'Mercadorias diversas', 'models.58.transport.cargo_product_type': '01',
      'models.58.transport.cargo_ncm': '12345678', 'models.58.transport.cargo_total_value': '250.00',
      'models.58.transport.cargo_unit_manifest': '01', 'models.58.transport.cargo_quantity_manifest': '1.0000'
    }
    for (const [field, value] of Object.entries(fields)) setFiscalField(mounted.root, field, value)
    await Vue.nextTick()
    const form = findNode(mounted.root, node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault: () => void }) => void)({ preventDefault() {} })

    assert.equal(emitted.length, 1)
    const bodyModels = emitted[0]!.models as Record<string, Record<string, unknown>>
    const transport = bodyModels['58']!.transport as Record<string, unknown>
    assert.equal(transport.issuer_type, 2)
    assert.equal(transport.rntrc, '12345678')
    assert.deepEqual(transport.route_ufs, ['SP', 'RJ'])
    assert.deepEqual(transport.municipalities, [{ name: 'Sao Paulo', code: '3550308' }, { name: 'Rio de Janeiro', code: '3304557' }])
    mounted.app.unmount()
  })

  it('emits an NF-e complement using the entered complementary amount instead of copying the sale amount', async () => {
    const fields = nfeSfcFacts('55')
    fields.document_kind = 'complement'
    fields.source_document_id = '91'
    fields['items.0.quantity'] = '1.0000'
    fields['items.0.unit_price'] = '10.0000000000'
    fields['items.0.gross_total'] = '10.00'
    fields['items.0.tax.icms_base'] = '10.00'
    fields['items.0.tax.icms_rate'] = '18.00'
    fields['items.0.tax.icms_value'] = '1.80'
    fields['source.totals.products'] = '10.00'
    fields['source.totals.invoice'] = '10.00'
    fields['source.totals.icms_base'] = '10.00'
    fields['source.totals.icms'] = '1.80'
    fields['source.totals.taxes_total'] = '1.80'
    fields['payment.0.amount'] = '10.00'
    const mounted = await submitDocumentSfc('55', fields)

    assert.equal(mounted.emitted.length, 1)
    const body = mounted.emitted[0]!
    assert.equal(body.document_kind, 'complement')
    assert.equal(body.source_document_id, 91)
    const source = body.source as Record<string, unknown>
    const recipient = source.recipient as Record<string, unknown>
    const item = (source.items as Array<Record<string, unknown>>)[0]!
    const tax = item.tax as Record<string, unknown>
    assert.equal((source.identity as Record<string, unknown>).purpose, 2)
    assert.equal(recipient.legal_name, 'Destinatário de teste')
    assert.equal(recipient.cnpj, '11222333000181')
    assert.equal(item.code, 'SKU-1')
    assert.equal(item.ncm, '12345678')
    assert.equal(item.cfop, '5102')
    assert.equal(item.unit, 'UN')
    assert.equal(item.quantity, '1.0000')
    assert.equal(item.unit_price, '10.0000000000')
    assert.equal(item.gross_total, '10.00')
    assert.equal(tax.origin, '0')
    assert.equal(tax.icms_cst, '00')
    assert.equal(tax.pis_cst, '07')
    assert.equal(tax.cofins_cst, '07')
    assert.equal(tax.icms_value, '1.80')
    assert.equal(((fiscalSale().commercial_snapshot?.lines ?? [])[0]?.gross_amount), '100.0000', 'source sale fixture remains unchanged')
    mounted.app.unmount()
  })

  it('sends an actual PKCS#12 file and preserves a present empty password in multipart data', async () => {
    const body = buildErpFiscalCertificateUpload({
      label: 'Certificado matriz',
      establishmentId: 41,
      certificate,
      password: ''
    })

    assert.ok(body instanceof FormData)
    assert.equal(body.get('label'), 'Certificado matriz')
    assert.equal(body.get('erp_establishment_id'), '41')
    const uploaded = body.get('certificate') as File
    assert.equal(uploaded.name, certificate.name)
    assert.equal(uploaded.type, certificate.type)
    assert.deepEqual(new Uint8Array(await uploaded.arrayBuffer()), new Uint8Array(await certificate.arrayBuffer()))
    assert.equal(body.get('password'), '')
    assert.equal(body.has('certificate_content'), false)
  })

  it('copies a posted-sale source snapshot with its original line identity and selected sale', () => {
    const source = buildErpFiscalDraftSource({
      kind: 'posted_sale',
      sale: {
        id: 73,
        status: 'posted',
        erp_establishment_id: 41,
        commercial_snapshot: {
          accepted: { actor_id: 7 },
          party: { id: 9, tax_id: '12345678000199', name: 'Cliente exemplo', address: { city: 'São Paulo' } },
          totals: { subtotal_amount: '31.0000', discount_amount: '0.0000', tax_amount: '0.0000', total_amount: '31.0000' },
          lines: [{
            id: 801,
            erp_product_variant_id: 17,
            quantity: '2.0000',
            unit_price: '15.5000',
            gross_amount: '31.0000',
            discount_amount: '0.0000',
            tax_amount: '0.0000',
            line_total: '31.0000',
            product_snapshot: {
              product: { sku: 'CAF-FALLBACK', name: 'Café', ncm: '09012100', origin_code: '0', icms_cst: '00', pis_cst: '07', cofins_cst: '07' },
              variant: { sku: 'CAF-001' },
              unit: { short_name: 'UN' },
              operation_nature: { cfop: '5102' }
            }
          }]
        }
      },
      facts: {
        identity: { operation_type: 1, destination_type: 1, purpose: 1, final_consumer: 1, presence_indicator: 1, print_type: 1 },
        recipient: { legal_name: 'Cliente exemplo', state_registration_indicator: 9, address: { street: 'Rua A', number: '1', district: 'Centro', city_code: '3550308', city_name: 'São Paulo', zip_code: '01001000' } },
        itemFacts: [{ description: 'Café', ind_total: 1, tax: { icms_base: '31.00', icms_rate: '18.00', icms_value: '5.58', pis_value: '0.00', cofins_value: '0.00' } }],
        totals: { products: '31.00', invoice: '31.00', icms_base: '31.00', icms: '5.58', icms_desonerated: '0.00', fcp: '0.00', st_base: '0.00', st: '0.00', fcp_st: '0.00', fcp_st_retained: '0.00', freight: '0.00', insurance: '0.00', discount: '0.00', ii: '0.00', ipi: '0.00', ipi_returned: '0.00', pis: '0.00', cofins: '0.00', other: '0.00', taxes_total: '5.58' },
        freight: { modality: 9 },
        payment: [{ method: '01', amount: '31.00' }]
      }
    })

    assert.equal(source.erp_sale_id, 73)
    assert.equal(source.document_kind, 'posted_sale')
    assert.equal(source.source.items[0]?.source_line_id, 801)
    assert.equal(source.source.items[0]?.erp_product_variant_id, 17)
    assert.equal(source.source.items[0]?.code, 'CAF-001')
    assert.equal(source.source.items[0]?.ncm, '09012100')
    assert.equal(source.source.items[0]?.tax.origin, '0')
    assert.equal(source.source.items[0]?.tax.icms_rate, '18.00')
    assert.equal(source.source.items[0]?.gross_total, '31.00')
    assert.deepEqual(source.source.totals, {
      products: '31.00', invoice: '31.00', icms_base: '31.00', icms: '5.58', icms_desonerated: '0.00', fcp: '0.00',
      st_base: '0.00', st: '0.00', fcp_st: '0.00', fcp_st_retained: '0.00', freight: '0.00', insurance: '0.00',
      discount: '0.00', ii: '0.00', ipi: '0.00', ipi_returned: '0.00', pis: '0.00', cofins: '0.00', other: '0.00', taxes_total: '5.58'
    })
  })

  it('rejects commercial line amounts smaller than a fiscal cent without rounding', () => {
    assert.throws(() => buildErpFiscalDraftSource({
      kind: 'posted_sale',
      sale: { id: 1, status: 'posted', erp_establishment_id: 41, commercial_snapshot: { lines: [{ id: 2, gross_amount: '1.0050', product_snapshot: {} }] } },
      facts: { identity: {}, recipient: {}, totals: {}, freight: {}, payment: [], itemFacts: [{ description: 'Item', ind_total: 1, tax: {} }] }
    }), /frações menores que centavos/i)
  })

  it('persists exact nonsecret request identity for uncertain document recovery without certificate material', () => {
    const record = createErpFiscalRecoveryRecord({
      actorId: 7,
      companyId: 22,
      action: 'document:draft',
      path: '/erp/fiscal/documents',
      idempotencyKey: 'fiscal-draft-22-73',
      body: {
        model: '55',
        erp_sale_id: 73,
        source: { items: [{ source_line_id: 801, gross_total: '31.00' }] },
        password: '',
        certificate: 'must-not-persist'
      }
    })

    assert.equal(record.actorId, 7)
    assert.equal(record.companyId, 22)
    assert.equal(record.action, 'document:draft')
    assert.equal(record.idempotencyKey, 'fiscal-draft-22-73')
    assert.deepEqual(record.body, {
      model: '55',
      erp_sale_id: 73,
      source: { items: [{ source_line_id: 801, gross_total: '31.00' }] }
    })
    assert.equal(JSON.stringify(record).includes('must-not-persist'), false)
    assert.equal(JSON.stringify(record).includes('password'), false)
  })

  it('maps an accepted posted return line back to its frozen sale line and current return CFOP', () => {
    const returnDocument = {
      id: 88,
      status: 'posted',
      erp_establishment_id: 41,
      erp_sale_id: 73,
      commercial_snapshot: { party: { tax_id: '12345678000199', name: 'Cliente exemplo' } },
      lines: [{
        id: 901,
        source_line_id: 801,
        quantity: '1.0000',
        subtotal_amount: '15.50',
        source_snapshot: { accepted: { actor_id: 7 }, line: { id: 801, unit_price: '15.5000', product_snapshot: { product: { ncm: '09012100', origin_code: '0', icms_cst: '00', pis_cst: '07', cofins_cst: '07' }, variant: { sku: 'CAF-001' }, unit: { short_name: 'UN' } } } }
      }]
    }
    const result = buildErpFiscalDraftSource({
      kind: 'return',
      returnDocument: returnDocument as ErpFiscalSale,
      returnLines: returnDocument.lines,
      facts: {
        identity: { operation_type: 0 },
        recipient: { state_registration_indicator: 9 },
        operationNature: { cfop: '1202', movement: 'in' },
        totals: { products: '15.50', invoice: '15.50' },
        freight: { modality: 9 },
        payment: [],
        itemFacts: [{ description: 'Café', ind_total: 1, tax: { icms_base: '15.50', icms_rate: '18.00', icms_value: '2.79', pis_value: '0.00', cofins_value: '0.00' } }]
      }
    })
    assert.equal(result.erp_sale_return_id, 88)
    assert.equal(result.erp_sale_id, undefined)
    assert.equal(result.source.items[0]?.source_line_id, 801)
    assert.equal(result.source.items[0]?.source_return_line_id, 901)
    assert.equal(result.source.items[0]?.code, 'CAF-001')
    assert.equal(result.source.items[0]?.cfop, '1202')
    assert.equal(result.source.items[0]?.gross_total, '15.50')
  })

  it('mounts the certificate SFC and emits the selected file with an empty password on submit', () => {
    const emitted: Array<Record<string, unknown>> = []
    const mounted = mountCertificateForm({
      mode: 'upload',
      establishments: [{ id: 41, name: 'Matriz' }],
      establishmentId: 41,
      canWrite: true,
      busy: false,
      onSubmit: (value: Record<string, unknown>) => emitted.push(value)
    })

    const label = findNode(mounted.root, node => node.tag === 'input' && node.props.name === 'label')
    const password = findNode(mounted.root, node => node.tag === 'input' && node.props.name === 'password')
    const file = findNode(mounted.root, node => node.tag === 'input' && node.props.type === 'file')
    assert.ok(label && password && file, 'the mounted certificate form exposes labelled label, password and file controls')
    ;(label.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: 'Certificado matriz' } })
    ;(password.props.onInput as (event: { target: { value: string } }) => void)({ target: { value: '' } })
    ;(file.props.onChange as (event: { target: { files: File[] } }) => void)({ target: { files: [certificate] } })
    const form = findNode(mounted.root, node => node.tag === 'form')
    assert.ok(form)
    ;(form.props.onSubmit as (event: { preventDefault: () => void }) => void)({ preventDefault() {} })

    assert.equal(emitted.length, 1)
    assert.equal(emitted[0]?.label, 'Certificado matriz')
    assert.equal(emitted[0]?.password, '')
    assert.equal(emitted[0]?.certificate, certificate)
    assert.equal(emitted[0]?.establishmentId, 41)
    mounted.app.unmount()
  })
})

describe('ERP fiscal durable workspace operations', () => {
  it('does not send production transmission when the server release gate is disabled', async (t) => {
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const mounted = loadWorkspaceComposable(async (path, options) => {
      calls.push({ path, options })
      if (path === '/erp/fiscal/configuration/41') return { data: {
        establishment: { id: 41, name: 'Matriz', cnpj: '12345678000199', status: 'active' },
        revision_id: 8,
        revision: 2,
        environment: 'production',
        issuer: { tax_regime: 3, state_registration: '123', uf: 'SP', address: { street: 'Rua A', number: '1', district: 'Centro', city_code: '3550308', city_name: 'São Paulo', zip_code: '01001000' } },
        models: { 55: { series: 1, next_number: 12, readiness: { status: 'locally_configured', blockers: [] } } },
        release_gates: { provider: 'unavailable', production_transmission_enabled: false, production_blocker: 'provider_unavailable' }
      } }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    await mounted.workspace.load(41)

    await assert.rejects(mounted.workspace.transmitDocument({ id: 91, model: '55', environment: 'production' }, 'tx-91'))
    assert.equal(calls.some(call => call.path === '/erp/fiscal/documents/91/transmit'), false)
    assert.equal(mounted.workspace.releaseMessage, 'Transmissão de produção bloqueada: provider_unavailable')
  })

  it('replays the exact uncertain transmission identity after reload and reconciles from current detail', async (t) => {
    const memory = new Map<string, string>()
    const storage = {
      get length() { return memory.size },
      key(index: number) { return [...memory.keys()][index] ?? null },
      getItem(key: string) { return memory.get(key) ?? null },
      setItem(key: string, value: string) { memory.set(key, value) },
      removeItem(key: string) { memory.delete(key) },
      clear() { memory.clear() }
    }
    Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: storage })
    t.after(() => {
      Reflect.deleteProperty(globalThis, 'sessionStorage')
    })
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    let loseResponse = true
    const api = async (path: string, options?: Record<string, unknown>) => {
      calls.push({ path, options })
      if (path === '/erp/fiscal/configuration/41') return { data: { establishment: { id: 41, name: 'Matriz', cnpj: '12345678000199', status: 'active' }, revision_id: 8, revision: 2, environment: 'homologation', issuer: null, models: { 55: { series: 1, next_number: 12, readiness: { status: 'locally_configured', blockers: [] } } }, release_gates: { provider: 'test-fixture', production_transmission_enabled: false, production_blocker: 'provider_unavailable' } } }
      if (path === '/erp/fiscal/documents/91/transmit' && loseResponse) {
        loseResponse = false
        throw new TypeError('connection lost after request')
      }
      if (path === '/erp/fiscal/documents/91/transmit') return { data: { id: 91, status: 'authorized', attempt: { id: 19, status: 'accepted' } } }
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, model: '55', environment: 'homologation', status: 'authorized', artifact_ready: true, claims_provider_authorization: true, attempts: [{ id: 19, action: 'document:transmit', status: 'accepted', idempotency_key: 'tx-91' }] } }
      return { data: [] }
    }
    const first = loadWorkspaceComposable(api, { actorId: 7, companyId: 22, grants: ['*'] })
    await first.workspace.load(41)
    await assert.rejects(first.workspace.transmitDocument({ id: 91, model: '55', environment: 'homologation' }, 'tx-91'))
    first.close()

    const reloaded = loadWorkspaceComposable(api, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(reloaded.close)
    await reloaded.workspace.recoverPendingOperations()
    const transmitCalls = calls.filter(call => call.path === '/erp/fiscal/documents/91/transmit')
    assert.equal(transmitCalls.length, 2)
    assert.deepEqual(transmitCalls.map(call => call.options?.body), [{ idempotency_key: 'tx-91' }, { idempotency_key: 'tx-91' }])
    assert.ok(calls.some(call => call.path === '/erp/fiscal/documents/91'))
    assert.equal(reloaded.workspace.documents.find((row: { id: number }) => row.id === 91)?.status, 'authorized')
    assert.equal(memory.size, 0, 'resolved exact operation identity is removed only after current detail confirms its result')
  })

  it('retains an unknown operation after recovery returns 404 and blocks a different operation', async (t) => {
    const memory = new Map<string, string>()
    const storage = {
      get length() { return memory.size },
      key(index: number) { return [...memory.keys()][index] ?? null },
      getItem(key: string) { return memory.get(key) ?? null },
      setItem(key: string, value: string) { memory.set(key, value) },
      removeItem(key: string) { memory.delete(key) },
      clear() { memory.clear() }
    }
    Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: storage })
    t.after(() => {
      Reflect.deleteProperty(globalThis, 'sessionStorage')
    })
    const calls: string[] = []
    const api = async (path: string) => {
      calls.push(path)
      if (path === '/erp/fiscal/configuration/41') return { data: { establishment: { id: 41, name: 'Matriz', cnpj: '12345678000199', status: 'active' }, revision_id: 8, revision: 2, environment: 'homologation', issuer: null, models: { 55: { series: 1, next_number: 12, readiness: { status: 'locally_configured', blockers: [] } } }, release_gates: { provider: 'test-fixture', production_transmission_enabled: false, production_blocker: 'provider_unavailable' } } }
      if (path === '/erp/fiscal/documents/91/transmit') throw new TypeError('connection lost')
      if (path === '/erp/fiscal/documents/91') throw { statusCode: 404 }
      return { data: [] }
    }
    const first = loadWorkspaceComposable(api, { actorId: 7, companyId: 22, grants: ['*'] })
    await first.workspace.load(41)
    await assert.rejects(first.workspace.transmitDocument({ id: 91, model: '55', environment: 'homologation' }, 'tx-91'))
    first.close()
    const reloaded = loadWorkspaceComposable(api, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(reloaded.close)
    await reloaded.workspace.recoverPendingOperations()

    assert.ok(memory.size > 0)
    await assert.rejects(reloaded.workspace.transmitDocument({ id: 91, model: '55', environment: 'homologation' }, 'tx-92'))
    assert.equal(calls.filter(path => path === '/erp/fiscal/documents/91/transmit').length, 2)
  })

  it('keeps authorized document status when a cancellation event is rejected', async (t) => {
    installSessionStorage(t)
    const apiCalls: string[] = []
    const mounted = loadWorkspaceComposable(async (path, options) => {
      apiCalls.push(`${options?.method ?? 'GET'} ${path}`)
      if (path === '/erp/fiscal/events' && options?.method === 'POST') return { data: { id: 31, erp_fiscal_document_id: 91, event_type: 'cancel', status: 'queued', idempotency_key: 'event-91' } }
      if (path === '/erp/fiscal/events/31/submit') return { data: { id: 31, erp_fiscal_document_id: 91, event_type: 'cancel', status: 'rejected', attempt: { id: 32, status: 'rejected', safe_result: { reason: 'event refused' } } } }
      if (path === '/erp/fiscal/events/31') return { data: { id: 31, erp_fiscal_document_id: 91, event_type: 'cancel', status: 'rejected', attempt: { id: 32, action: 'event:submit', status: 'rejected', safe_result: { reason: 'event refused' } } } }
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, model: '55', status: 'authorized', claims_provider_authorization: true, supported_events: ['cancel'], attempts: [] } }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    mounted.workspace.configuration = { release_gates: { provider: 'test-provider', production_transmission_enabled: false, production_blocker: null } }
    mounted.workspace.documents = [{ id: 91, model: '55', status: 'authorized', claims_provider_authorization: true }]
    mounted.workspace.selectedDocument = { id: 91, model: '55', environment: 'homologation', status: 'authorized', supported_events: ['cancel'] }

    await mounted.workspace.createAndSubmitEvent({ id: 91, model: '55', status: 'authorized', supported_events: ['cancel'] }, 'cancel', { justification: 'Solicitação válida do emitente' }, 'event-91')

    assert.equal(mounted.workspace.documents[0]?.status, 'authorized')
    assert.ok(apiCalls.includes('POST /erp/fiscal/events/31/submit'))
  })

  it('keeps a newly created event draft from submitting after the focused document changes', async (t) => {
    installSessionStorage(t)
    const calls: string[] = []
    let resolveDraft!: (value: unknown) => void
    const mounted = loadWorkspaceComposable(async (path, options) => {
      calls.push(`${options?.method ?? 'GET'} ${path}`)
      if (path === '/erp/fiscal/events' && options?.method === 'POST') {
        return await new Promise((resolve) => {
          resolveDraft = resolve
        })
      }
      if (path === '/erp/fiscal/documents/92') return { data: { id: 92, model: '55', environment: 'homologation', status: 'authorized', artifact_ready: true, claims_provider_authorization: true } }
      if (path === '/erp/fiscal/events/31/submit') return { data: { id: 31, erp_fiscal_document_id: 91, event_type: 'cancel', status: 'accepted' } }
      if (path === '/erp/fiscal/events/31') return { data: { id: 31, erp_fiscal_document_id: 91, event_type: 'cancel', status: 'accepted' } }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    mounted.workspace.configuration = { release_gates: { provider: 'test-provider', production_transmission_enabled: false, production_blocker: null } }
    mounted.workspace.selectedDocument = { id: 91, model: '55', environment: 'homologation', status: 'authorized', artifact_ready: true, claims_provider_authorization: true, supported_events: ['cancel'] }

    const pending = mounted.workspace.createAndSubmitEvent(mounted.workspace.selectedDocument, 'cancel', { justification: 'Solicitação de cancelamento confirmada' }, 'event-91')
    await mounted.workspace.loadDocument(92)
    resolveDraft({ data: { id: 31, erp_fiscal_document_id: 91, event_type: 'cancel', status: 'queued', idempotency_key: 'event-91' } })
    await pending

    assert.equal(calls.includes('POST /erp/fiscal/events/31/submit'), false)
    assert.equal(mounted.workspace.selectedDocument.id, 92)
  })

  it('does not dispatch recovery work when the current company has no pending identity', async (t) => {
    const calls: string[] = []
    const mounted = loadWorkspaceComposable(async (path) => {
      calls.push(path)
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)

    await mounted.workspace.recoverPendingOperations()

    assert.deepEqual(calls, [])
    assert.equal(mounted.workspace.recoveryMessage, '')
  })

  it('invalidates the previous sale snapshot immediately when a new sale is requested', async (t) => {
    let resolveSale!: (value: unknown) => void
    const mounted = loadWorkspaceComposable(async () => await new Promise((resolve) => {
      resolveSale = resolve
    }), { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    mounted.workspace.selectedEstablishmentId = 6
    mounted.workspace.selectedSale = fiscalSale()

    const pending = mounted.workspace.loadSaleSnapshot(74)
    assert.equal(mounted.workspace.selectedSale, null)
    resolveSale({ data: { ...fiscalSale(), id: 74 } })
    await pending
    assert.equal(mounted.workspace.selectedSale?.id, 74)
  })

  it('recovers queue by its exact accepted attempt even while the document remains queued', async (t) => {
    const memory = installSessionStorage(t)
    const record = createErpFiscalRecoveryRecord({ actorId: 7, companyId: 22, action: 'document:queue', path: '/erp/fiscal/documents/91/queue', idempotencyKey: 'queue-91', body: { idempotency_key: 'queue-91' }, resourceId: 91, recoveryMode: 'read' })
    memory.set('opmoni:erp-fiscal:recovery:v1:7:22', JSON.stringify(record))
    const mounted = loadWorkspaceComposable(async (path) => {
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, model: '55', environment: 'homologation', status: 'queued', artifact_ready: false, claims_provider_authorization: false, attempts: [{ id: 17, action: 'document:queue', status: 'accepted', idempotency_key: 'queue-91', safe_result: null }] } }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)

    await mounted.workspace.recoverPendingOperations()

    assert.equal(memory.size, 0)
  })

  it('does not let an old authorized resource status clear a still-pending consult attempt', async (t) => {
    const memory = installSessionStorage(t)
    const record = createErpFiscalRecoveryRecord({ actorId: 7, companyId: 22, action: 'document:consult', path: '/erp/fiscal/documents/91/consult', idempotencyKey: 'consult-91', body: { idempotency_key: 'consult-91' }, resourceId: 91, recoveryMode: 'read' })
    memory.set('opmoni:erp-fiscal:recovery:v1:7:22', JSON.stringify(record))
    const mounted = loadWorkspaceComposable(async (path) => {
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, model: '55', environment: 'homologation', status: 'authorized', artifact_ready: true, claims_provider_authorization: true, attempts: [{ id: 18, action: 'document:consult', status: 'pending', idempotency_key: 'consult-91', safe_result: null }] } }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)

    await mounted.workspace.recoverPendingOperations()

    assert.ok(memory.size > 0)
  })

  it('recovers a queued event draft and submits only the same event after explicit continuation', async (t) => {
    const memory = installSessionStorage(t)
    const record = createErpFiscalRecoveryRecord({ actorId: 7, companyId: 22, action: 'event:draft', path: '/erp/fiscal/events', idempotencyKey: 'event-same-key', body: { erp_fiscal_document_id: 91, event_type: 'cancel', idempotency_key: 'event-same-key' } })
    memory.set('opmoni:erp-fiscal:recovery:v1:7:22', JSON.stringify(record))
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const event = { id: 31, erp_fiscal_document_id: 91, event_type: 'cancel', status: 'queued', idempotency_key: 'event-same-key' }
    let submitted = false
    const mounted = loadWorkspaceComposable(async (path, options) => {
      calls.push({ path, options })
      if (path === '/erp/fiscal/events') return { data: event }
      if (path === '/erp/fiscal/events/31') return { data: submitted ? { ...event, status: 'accepted', attempt: { id: 39, action: 'event:submit', status: 'accepted', safe_result: null } } : event }
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, model: '55', environment: 'homologation', status: 'authorized', artifact_ready: true, claims_provider_authorization: true, supported_events: ['cancel'] } }
      if (path === '/erp/fiscal/events/31/submit') {
        submitted = true
        return { data: { ...event, status: 'queued' } }
      }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    mounted.workspace.configuration = { environment: 'homologation', release_gates: { provider: 'test-provider', production_transmission_enabled: false, production_blocker: null } }

    await mounted.workspace.recoverPendingOperations()
    assert.equal(mounted.workspace.recoverableEventDraft.id, 31)
    assert.equal(calls.some(call => call.path.endsWith('/submit')), false, 'recovery does not silently submit the queued event')
    await mounted.workspace.continueRecoveredEventDraft()

    const submission = calls.find(call => call.path === '/erp/fiscal/events/31/submit')
    assert.deepEqual(submission?.options?.body, { idempotency_key: 'event-same-key' })
    assert.equal(calls.filter(call => call.path === '/erp/fiscal/events').length, 1, 'continuation does not create a replacement event row')
    assert.equal(memory.size, 0)
  })

  it('restores a recovered event draft after a definitive submit refusal and continues the same event explicitly', async (t) => {
    const memory = installSessionStorage(t)
    const record = createErpFiscalRecoveryRecord({ actorId: 7, companyId: 22, action: 'event:draft', path: '/erp/fiscal/events', idempotencyKey: 'event-retry-key', body: { erp_fiscal_document_id: 91, event_type: 'cancel', idempotency_key: 'event-retry-key' }, resourceId: 31, recoveryMode: 'read' })
    memory.set('opmoni:erp-fiscal:recovery:v1:7:22', JSON.stringify(record))
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const event = { id: 31, erp_fiscal_document_id: 91, event_type: 'cancel', status: 'queued', idempotency_key: 'event-retry-key' }
    let refuseSubmit = true
    const mounted = loadWorkspaceComposable(async (path, options) => {
      calls.push({ path, options })
      if (path === '/erp/fiscal/events/31') return { data: refuseSubmit ? event : { ...event, status: 'accepted', attempt: { id: 39, action: 'event:submit', status: 'accepted', idempotency_key: 'event-retry-key', safe_result: null } } }
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, model: '55', environment: 'homologation', status: 'authorized', supported_events: ['cancel'] } }
      if (path === '/erp/fiscal/events/31/submit' && refuseSubmit) throw { statusCode: 403, data: { message: 'Operação bloqueada antes do envio.' } }
      if (path === '/erp/fiscal/events/31/submit') return { data: { ...event, status: 'queued' } }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    mounted.workspace.configuration = { environment: 'homologation', release_gates: { provider: 'test-provider', production_transmission_enabled: false, production_blocker: null } }

    await mounted.workspace.recoverPendingOperations()
    await assert.rejects(mounted.workspace.continueRecoveredEventDraft())
    const restoredRaw = [...memory.values()][0] ?? ''
    const restored = JSON.parse(restoredRaw) as { action: string, resourceId: number | null, idempotencyKey: string } | null
    const restoredDraftId = mounted.workspace.recoverableEventDraft?.id
    refuseSubmit = false
    await mounted.workspace.continueRecoveredEventDraft()

    const submitCalls = calls.filter(call => call.path === '/erp/fiscal/events/31/submit')
    assert.equal(restored?.action, 'event:draft')
    assert.equal(restored?.resourceId, 31)
    assert.equal(restored?.idempotencyKey, 'event-retry-key')
    assert.equal(restoredDraftId, 31, 'the same created event is available for a fresh explicit confirmation')
    assert.equal(submitCalls.length, 2, 'one definitive refusal is followed by one explicit retry of the same submit path')
    assert.deepEqual(submitCalls.map(call => call.options?.body), [{ idempotency_key: 'event-retry-key' }, { idempotency_key: 'event-retry-key' }])
    assert.equal(calls.filter(call => call.path === '/erp/fiscal/events' && call.options?.method === 'POST').length, 0, 'continuation never creates a second event row')
  })

  it('keeps an uncertain recovered-event submission inspection-only after a timeout', async (t) => {
    const memory = installSessionStorage(t)
    const draft = createErpFiscalRecoveryRecord({ actorId: 7, companyId: 22, action: 'event:draft', path: '/erp/fiscal/events', idempotencyKey: 'event-timeout-key', body: { erp_fiscal_document_id: 91, event_type: 'cancel', idempotency_key: 'event-timeout-key' }, resourceId: 31, recoveryMode: 'read' })
    memory.set('opmoni:erp-fiscal:recovery:v1:7:22', JSON.stringify(draft))
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const event = { id: 31, erp_fiscal_document_id: 91, event_type: 'cancel', status: 'queued', idempotency_key: 'event-timeout-key' }
    const mounted = loadWorkspaceComposable(async (path, options) => {
      calls.push({ path, options })
      if (path === '/erp/fiscal/events/31') return { data: { ...event, attempt: { id: 40, action: 'event:submit', status: 'pending', idempotency_key: 'event-timeout-key', safe_result: null } } }
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, model: '55', environment: 'homologation', status: 'authorized', supported_events: ['cancel'] } }
      if (path === '/erp/fiscal/events/31/submit') throw new TypeError('connection lost after event submission')
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    mounted.workspace.configuration = { environment: 'homologation', release_gates: { provider: 'test-provider', production_transmission_enabled: false, production_blocker: null } }

    await mounted.workspace.recoverPendingOperations()
    await assert.rejects(mounted.workspace.continueRecoveredEventDraft())
    await mounted.workspace.recoverPendingOperations()

    const submitCalls = calls.filter(call => call.path === '/erp/fiscal/events/31/submit')
    const stored = JSON.parse([...memory.values()][0] ?? 'null') as { action: string, recoveryMode: string, idempotencyKey: string }
    assert.equal(submitCalls.length, 1, 'reload inspection does not automatically repeat an uncertain external submit')
    assert.equal(stored.action, 'event:submit')
    assert.equal(stored.recoveryMode, 'read')
    assert.equal(stored.idempotencyKey, 'event-timeout-key')
    assert.ok(mounted.workspace.pendingRecovery, 'the exact identity stays locked until a definitive event attempt is visible')
  })

  it('keeps the submit inspection lock when the submit succeeded but its detail read is refused', async (t) => {
    const memory = installSessionStorage(t)
    const draft = createErpFiscalRecoveryRecord({ actorId: 7, companyId: 22, action: 'event:draft', path: '/erp/fiscal/events', idempotencyKey: 'event-detail-refused', body: { erp_fiscal_document_id: 91, event_type: 'cancel', idempotency_key: 'event-detail-refused' }, resourceId: 31, recoveryMode: 'read' })
    memory.set('opmoni:erp-fiscal:recovery:v1:7:22', JSON.stringify(draft))
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const event = { id: 31, erp_fiscal_document_id: 91, event_type: 'cancel', status: 'queued', idempotency_key: 'event-detail-refused' }
    let eventReads = 0
    const mounted = loadWorkspaceComposable(async (path, options) => {
      calls.push({ path, options })
      if (path === '/erp/fiscal/events/31') {
        eventReads++
        if (eventReads > 1) throw { statusCode: 403, data: { message: 'Consulta do evento não permitida.' } }
        return { data: event }
      }
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, model: '55', environment: 'homologation', status: 'authorized', supported_events: ['cancel'] } }
      if (path === '/erp/fiscal/events/31/submit') return { data: { ...event, status: 'accepted' } }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    mounted.workspace.configuration = { environment: 'homologation', release_gates: { provider: 'test-provider', production_transmission_enabled: false, production_blocker: null } }

    await mounted.workspace.recoverPendingOperations()
    await assert.rejects(mounted.workspace.continueRecoveredEventDraft())

    const stored = JSON.parse([...memory.values()][0] ?? 'null') as { action: string, recoveryMode: string, idempotencyKey: string } | null
    assert.equal(calls.filter(call => call.path === '/erp/fiscal/events/31/submit').length, 1, 'the submit was dispatched exactly once')
    assert.equal(stored?.action, 'event:submit', 'a failed post-submit detail read retains the inspection identity')
    assert.equal(stored?.recoveryMode, 'read')
    assert.equal(stored?.idempotencyKey, 'event-detail-refused')
    assert.equal(mounted.workspace.recoverableEventDraft, null, 'a successful submit is never restored as a resumable draft')
  })

  it('retains an event draft after its initial submit is definitively refused', async (t) => {
    const memory = installSessionStorage(t)
    const calls: string[] = []
    const mounted = loadWorkspaceComposable(async (path, options) => {
      calls.push(`${options?.method ?? 'GET'} ${path}`)
      if (path === '/erp/fiscal/events' && options?.method === 'POST') return { data: { id: 31, erp_fiscal_document_id: 91, event_type: 'cancel', status: 'queued', idempotency_key: 'event-created-key' } }
      if (path === '/erp/fiscal/events/31/submit') throw { statusCode: 422, data: { errors: { justification: ['Justificativa recusada antes do envio.'] } } }
      if (path === '/erp/fiscal/events/31') return { data: { id: 31, erp_fiscal_document_id: 91, event_type: 'cancel', status: 'queued', idempotency_key: 'event-created-key' } }
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, model: '55', environment: 'homologation', status: 'authorized', supported_events: ['cancel'] } }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    mounted.workspace.configuration = { environment: 'homologation', release_gates: { provider: 'test-provider', production_transmission_enabled: false, production_blocker: null } }
    const document = { id: 91, model: '55', environment: 'homologation', status: 'authorized', supported_events: ['cancel'] }
    mounted.workspace.selectedDocument = document

    await assert.rejects(mounted.workspace.createAndSubmitEvent(document, 'cancel', { justification: 'Solicitação válida do emitente' }, 'event-created-key'))
    const stored = JSON.parse([...memory.values()][0] ?? 'null') as { action: string, resourceId: number | null, idempotencyKey: string } | null
    await mounted.workspace.recoverPendingOperations()

    assert.equal(stored?.action, 'event:draft')
    assert.equal(stored?.resourceId, 31)
    assert.equal(stored?.idempotencyKey, 'event-created-key')
    assert.equal(mounted.workspace.recoverableEventDraft?.id, 31)
    assert.equal(calls.filter(call => call === 'POST /erp/fiscal/events').length, 1)
  })

  it('does not submit a newly created event when persisting its resumable identity fails', async (t) => {
    const memory = installSessionStorage(t)
    const storage = globalThis.sessionStorage
    const originalSetItem = storage.setItem.bind(storage)
    let writes = 0
    storage.setItem = (key, value) => {
      writes++
      if (writes === 2) throw new Error('storage quota exceeded')
      originalSetItem(key, value)
    }
    let submits = 0
    const mounted = loadWorkspaceComposable(async (path, options) => {
      if (path === '/erp/fiscal/events' && options?.method === 'POST') return { data: { id: 31, erp_fiscal_document_id: 91, event_type: 'cancel', status: 'queued', idempotency_key: 'event-storage-key' } }
      if (path === '/erp/fiscal/events/31/submit') submits++
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    mounted.workspace.configuration = { environment: 'homologation', release_gates: { provider: 'test-provider', production_transmission_enabled: false, production_blocker: null } }
    const document = { id: 91, model: '55', environment: 'homologation', status: 'authorized', supported_events: ['cancel'] }
    mounted.workspace.selectedDocument = document

    await assert.rejects(mounted.workspace.createAndSubmitEvent(document, 'cancel', { justification: 'Solicitação válida do emitente' }, 'event-storage-key'))

    const stored = JSON.parse([...memory.values()][0] ?? 'null') as { action: string, idempotencyKey: string } | null
    assert.equal(submits, 0, 'the provider receives no submit when the resumable identity cannot be persisted')
    assert.equal(stored?.action, 'event:draft')
    assert.equal(stored?.idempotencyKey, 'event-storage-key')
  })

  it('reconciles a known document identity with show permission after its mutation permission is revoked', async (t) => {
    const memory = installSessionStorage(t)
    const record = createErpFiscalRecoveryRecord({ actorId: 7, companyId: 22, action: 'document:transmit', path: '/erp/fiscal/documents/91/transmit', idempotencyKey: 'tx-read-only', body: { idempotency_key: 'tx-read-only' }, resourceId: 91, recoveryMode: 'replay' })
    memory.set('opmoni:erp-fiscal:recovery:v1:7:22', JSON.stringify(record))
    const calls: Array<{ path: string, options?: Record<string, unknown> }> = []
    const mounted = loadWorkspaceComposable(async (path, options) => {
      calls.push({ path, options })
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, idempotency_key: 'tx-read-only', model: '55', environment: 'homologation', status: 'authorized', attempts: [{ id: 19, action: 'document:transmit', status: 'pending', idempotency_key: 'tx-read-only', safe_result: null }] } }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['erp.fiscal.documents.show'] })
    t.after(mounted.close)

    await mounted.workspace.recoverPendingOperations()

    assert.deepEqual(calls.map(call => call.path), ['/erp/fiscal/documents/91'], 'read-only recovery performs the exact authorized show request')
    assert.equal(calls.some(call => call.options?.method === 'POST'), false, 'read-only recovery never replays a mutation')
    assert.ok(mounted.workspace.pendingRecovery, 'the still-pending attempt remains locked to its original identity')
    assert.ok(memory.size > 0)
  })

  it('uses event and invalidation show grants for detail recovery, not index grants', async (t) => {
    const cases = [
      { action: 'event:submit', path: '/erp/fiscal/events/31/submit', detail: '/erp/fiscal/events/31', resourceId: 31, key: 'event-show-key', show: 'erp.fiscal.events.show', index: 'erp.fiscal.events.index', payload: { id: 31, idempotency_key: 'event-show-key', status: 'queued', attempt: { id: 1, action: 'event:submit', status: 'pending', idempotency_key: 'event-show-key', safe_result: null } } },
      { action: 'invalidation:submit', path: '/erp/fiscal/invalidations/41/submit', detail: '/erp/fiscal/invalidations/41', resourceId: 41, key: 'invalidation-show-key', show: 'erp.fiscal.invalidations.show', index: 'erp.fiscal.invalidations.index', payload: { id: 41, idempotency_key: 'invalidation-show-key', status: 'queued', attempt: { id: 2, action: 'invalidation:submit', status: 'pending', idempotency_key: 'invalidation-show-key', safe_result: null } } }
    ]
    for (const fixture of cases) {
      const showMemory = installSessionStorage(t)
      const showRecord = createErpFiscalRecoveryRecord({ actorId: 7, companyId: 22, action: fixture.action, path: fixture.path, idempotencyKey: fixture.key, body: { idempotency_key: fixture.key }, resourceId: fixture.resourceId, recoveryMode: 'read' })
      showMemory.set('opmoni:erp-fiscal:recovery:v1:7:22', JSON.stringify(showRecord))
      const showCalls: string[] = []
      const showWorkspace = loadWorkspaceComposable(async (path) => {
        showCalls.push(path)
        if (path === fixture.detail) return { data: fixture.payload }
        if (path === '/erp/fiscal/events?per_page=100') throw new Error('event index unavailable')
        return { data: [] }
      }, { actorId: 7, companyId: 22, grants: [fixture.show] })
      t.after(showWorkspace.close)
      await showWorkspace.workspace.recoverPendingOperations()
      assert.ok(showCalls.includes(fixture.detail), `${fixture.show} permits its exact detail recovery`)
      assert.ok(showWorkspace.workspace.pendingRecovery)

      const indexMemory = installSessionStorage(t)
      indexMemory.set('opmoni:erp-fiscal:recovery:v1:7:22', JSON.stringify(showRecord))
      const indexCalls: string[] = []
      const indexWorkspace = loadWorkspaceComposable(async (path) => {
        indexCalls.push(path)
        return { data: fixture.payload }
      }, { actorId: 7, companyId: 22, grants: [fixture.index] })
      t.after(indexWorkspace.close)
      await indexWorkspace.workspace.recoverPendingOperations()
      assert.equal(indexCalls.includes(fixture.detail), false, `${fixture.index} alone does not authorize a detail endpoint`)
    }
  })

  it('keeps known-resource detail recovery independent of list failures and rejects malformed recovery scope quietly', async (t) => {
    const memory = installSessionStorage(t)
    const record = createErpFiscalRecoveryRecord({ actorId: 7, companyId: 22, action: 'document:transmit', path: '/erp/fiscal/documents/91/transmit', idempotencyKey: 'tx-list-failure', body: { idempotency_key: 'tx-list-failure' }, resourceId: 91, recoveryMode: 'read' })
    memory.set('opmoni:erp-fiscal:recovery:v1:7:22', JSON.stringify(record))
    const calls: string[] = []
    const mounted = loadWorkspaceComposable(async (path) => {
      calls.push(path)
      if (path === '/erp/establishments?per_page=100') return { data: [{ id: 6, name: 'Matriz', cnpj: '11222333000181', status: 'active' }] }
      if (path === '/erp/fiscal/documents?per_page=100') throw new Error('document index unavailable')
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, idempotency_key: 'tx-list-failure', model: '55', environment: 'homologation', status: 'authorized', attempts: [{ id: 21, action: 'document:transmit', status: 'pending', idempotency_key: 'tx-list-failure', safe_result: null }] } }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['erp.fiscal.documents.index', 'erp.fiscal.documents.show'] })
    t.after(mounted.close)
    await mounted.workspace.load(6)
    await mounted.workspace.recoverPendingOperations()
    assert.ok(calls.includes('/erp/fiscal/documents/91'), 'authorized page-level detail reconciliation still runs after the list fails')
    mounted.close()

    const malformedMemory = installSessionStorage(t)
    const malformed = { ...record, path: '/erp/fiscal/documents/92/transmit' }
    malformedMemory.set('opmoni:erp-fiscal:recovery:v1:7:22', JSON.stringify(malformed))
    const malformedCalls: string[] = []
    const malformedWorkspace = loadWorkspaceComposable(async (path) => {
      malformedCalls.push(path)
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(malformedWorkspace.close)
    await malformedWorkspace.workspace.recoverPendingOperations()
    assert.deepEqual(malformedCalls, [], 'a path/resource mismatch stays quiet and issues no request')

    const noCompanyMemory = installSessionStorage(t)
    noCompanyMemory.set('opmoni:erp-fiscal:recovery:v1:7:22', JSON.stringify(record))
    const noCompanyCalls: string[] = []
    const noCompanyWorkspace = loadWorkspaceComposable(async (path) => {
      noCompanyCalls.push(path)
      return { data: [] }
    }, { actorId: 7, companyId: null, grants: ['*'] })
    t.after(noCompanyWorkspace.close)
    await noCompanyWorkspace.workspace.recoverPendingOperations()
    assert.deepEqual(noCompanyCalls, [], 'recovery remains quiet when no active company is selected')
  })

  it('locks certificate writes after an ambiguous response until the operator acknowledges current metadata', async (t) => {
    const memory = installSessionStorage(t)
    let writes = 0
    let metadataReads = 0
    const mounted = loadWorkspaceComposable(async (path, options) => {
      if (path === '/erp/fiscal/certificates' && options?.method === 'POST') {
        writes++
        throw new TypeError('connection lost after certificate request')
      }
      if (path === '/erp/fiscal/certificates?per_page=100') {
        metadataReads++
        return { data: metadataReads === 1
          ? [{ id: 4, label: 'Anterior', cnpj: '12345678000199', erp_establishment_id: 6, revision: 1, revision_id: 10, status: 'active', certificate_fingerprint: 'old-fingerprint', valid_from: null, valid_until: null }]
          : [{ id: 4, label: 'Anterior', cnpj: '12345678000199', erp_establishment_id: 6, revision: 1, revision_id: 10, status: 'active', certificate_fingerprint: 'old-fingerprint', valid_from: null, valid_until: null }, { id: 5, label: 'Certificado matriz', cnpj: '12345678000199', erp_establishment_id: 6, revision: 1, revision_id: 11, status: 'active', certificate_fingerprint: 'public-fingerprint', valid_from: null, valid_until: null }] }
      }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    mounted.workspace.certificates = [{ id: 4, label: 'Anterior', cnpj: '12345678000199', erp_establishment_id: 6, revision: 1, revision_id: 10, status: 'active', certificate_fingerprint: 'old-fingerprint', valid_from: null, valid_until: null }]
    const input = { label: 'Certificado matriz', establishmentId: 6, certificate, password: 'never-store-this' }

    await mounted.workspace.uploadCertificate(input)
    await assert.rejects(mounted.workspace.uploadCertificate({ ...input, certificate: new File(['replacement'], 'replacement.p12'), password: 'second-secret' }), /sem resultado confirmado/i)

    const stored = [...memory.values()].join('\n')
    assert.equal(writes, 1)
    assert.equal(stored.includes('never-store-this'), false)
    assert.equal(stored.includes('second-secret'), false)
    assert.equal(stored.includes('pkcs12-private-bytes'), false)
    assert.ok(mounted.workspace.pendingCertificateRecovery)
    await mounted.workspace.recoverCertificateMutation()
    assert.ok(mounted.workspace.pendingCertificateRecovery, 'metadata inspection alone does not prove which file was sent')
    assert.ok(mounted.workspace.certificateRecoveryReview)
    mounted.workspace.confirmCertificateRecoveryReview()
    assert.equal(mounted.workspace.pendingCertificateRecovery, null)
    assert.equal(mounted.workspace.certificates.some((row: { id: number }) => row.id === 5), true)
  })

  it('admits only one certificate mutation across deferred baseline reads with a frozen clock', async (t) => {
    installSessionStorage(t)
    const originalDate = Object.getOwnPropertyDescriptor(globalThis, 'Date')
    const NativeDate = globalThis.Date
    const FrozenDate = class extends NativeDate {
      constructor(value?: string | number) {
        super(value ?? '2026-10-21T00:00:00.000Z')
      }
    }
    Object.defineProperty(globalThis, 'Date', { configurable: true, value: FrozenDate })
    t.after(() => {
      if (originalDate) Object.defineProperty(globalThis, 'Date', originalDate)
    })
    const baselineResolvers: Array<(value: unknown) => void> = []
    let writes = 0
    const mounted = loadWorkspaceComposable(async (path, options) => {
      if (path === '/erp/fiscal/certificates?per_page=100' && !options?.method) {
        return await new Promise(resolve => baselineResolvers.push(resolve))
      }
      if (path === '/erp/fiscal/certificates' && options?.method === 'POST') {
        writes++
        return { data: { id: 5, label: 'Certificado matriz', erp_establishment_id: 6, revision: 1, revision_id: 11, status: 'active' } }
      }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    const input = { label: 'Certificado matriz', establishmentId: 6, certificate, password: '' }

    const first = mounted.workspace.uploadCertificate(input)
    const second = mounted.workspace.uploadCertificate({ ...input, certificate: new File(['second'], 'second.p12') })
    for (const resolve of baselineResolvers) resolve({ data: [] })
    await Promise.allSettled([first, second])

    assert.equal(baselineResolvers.length, 1, 'a second upload is refused before its baseline metadata request')
    assert.equal(writes, 1, 'at most one non-idempotent multipart mutation is dispatched')
  })

  it('rechecks the persisted certificate owner after baselines race across workspace instances', async (t) => {
    const memory = installSessionStorage(t)
    const baselineResolvers: Array<(value: unknown) => void> = []
    let releaseFirstPost: ((value: unknown) => void) | null = null
    let writes = 0
    const api = async (path: string, options?: Record<string, unknown>) => {
      if (path === '/erp/fiscal/certificates?per_page=100' && !options?.method) {
        return await new Promise(resolve => baselineResolvers.push(resolve))
      }
      if (path === '/erp/fiscal/certificates' && options?.method === 'POST') {
        writes++
        if (writes === 1) {
          return await new Promise((resolve) => {
            releaseFirstPost = resolve
          })
        }
        return { data: { id: 5, label: 'Certificado matriz', erp_establishment_id: 6, revision: 1, revision_id: 11, status: 'active' } }
      }
      return { data: [] }
    }
    const first = loadWorkspaceComposable(api, { actorId: 7, companyId: 22, grants: ['*'] })
    const second = loadWorkspaceComposable(api, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(first.close)
    t.after(second.close)
    const input = { label: 'Certificado matriz', establishmentId: 6, certificate, password: '' }
    const firstUpload = first.workspace.uploadCertificate(input)
    const secondUpload = second.workspace.uploadCertificate({ ...input, certificate: new File(['second'], 'second.p12') })
    assert.equal(baselineResolvers.length, 2, 'separate workspace instances can both reach their baseline read')

    baselineResolvers[0]!({ data: [] })
    let secondBaselineResolved = false
    try {
      for (let attempt = 0; attempt < 10 && !releaseFirstPost; attempt++) await Vue.nextTick()
      assert.ok(releaseFirstPost, 'the first baseline persists its owner and reaches the deferred mutation')
      const firstOwner = [...memory.values()].join('\n')
      baselineResolvers[1]!({ data: [] })
      secondBaselineResolved = true
      await secondUpload

      assert.equal(writes, 1, 'the second baseline observes the persisted owner while the first mutation is active')
      assert.equal([...memory.values()].join('\n'), firstOwner, 'the second instance cannot overwrite the active operation identity')
      assert.match(second.workspace.errorMessage, /Outra alteração de certificado foi iniciada/i, 'the refused second upload reports the active operation to the operator')
    } finally {
      if (!secondBaselineResolved) baselineResolvers[1]!({ data: [] })
      releaseFirstPost?.({ data: { id: 5, label: 'Certificado matriz', erp_establishment_id: 6, revision: 1, revision_id: 11, status: 'active' } })
      await Promise.allSettled([firstUpload, secondUpload])
    }
  })

  it('keeps an unknown certificate mutation locked after reload without replaying its secret-bearing request', async (t) => {
    const memory = installSessionStorage(t)
    let writes = 0
    const api = async (path: string, options?: Record<string, unknown>) => {
      if (path === '/erp/fiscal/certificates?per_page=100' && !options?.method) return { data: [] }
      if (path === '/erp/fiscal/certificates' && options?.method === 'POST') {
        writes++
        throw new TypeError('connection lost after certificate request')
      }
      return { data: [] }
    }
    const first = loadWorkspaceComposable(api, { actorId: 7, companyId: 22, grants: ['*'] })
    await first.workspace.uploadCertificate({ label: 'Certificado novo', establishmentId: 6, certificate, password: 'never-persist-this' })
    first.close()
    const stored = [...memory.values()].join('\n')
    const reloaded = loadWorkspaceComposable(api, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(reloaded.close)

    await reloaded.workspace.recoverPendingOperations()

    assert.equal(writes, 1, 'reload recovery performs metadata inspection only and does not replay the multipart POST')
    assert.ok(reloaded.workspace.pendingCertificateRecovery)
    assert.equal(stored.includes('never-persist-this'), false)
    assert.equal(stored.includes('pkcs12-private-bytes'), false)
  })

  it('does not let metadata inspection or acknowledgment release a certificate lock during its active POST', async (t) => {
    const memory = installSessionStorage(t)
    let releasePost!: (value: unknown) => void
    let certificateReads = 0
    const mounted = loadWorkspaceComposable(async (path, options) => {
      if (path === '/erp/fiscal/certificates?per_page=100' && !options?.method) {
        certificateReads++
        return { data: [{ id: 4, label: 'Anterior', cnpj: '12345678000199', erp_establishment_id: 6, revision: 1, revision_id: 10, status: 'active', certificate_fingerprint: 'old', valid_from: null, valid_until: null }] }
      }
      if (path === '/erp/fiscal/certificates' && options?.method === 'POST') {
        return await new Promise((resolve) => {
          releasePost = resolve
        })
      }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    const upload = mounted.workspace.uploadCertificate({ label: 'Certificado novo', establishmentId: 6, certificate, password: 'do-not-save' })
    try {
      for (let attempt = 0; attempt < 10 && !releasePost; attempt++) await Vue.nextTick()
      assert.ok(releasePost, 'the test reaches the deferred multipart POST before checking its active lock')
      const recordBeforeReview = [...memory.values()].join('\n')
      await mounted.workspace.recoverCertificateMutation()
      try {
        mounted.workspace.confirmCertificateRecoveryReview()
      } catch {
        // An active owner may reject manual acknowledgment outright.
      }

      assert.ok(mounted.workspace.pendingCertificateRecovery, 'manual acknowledgment cannot clear an actively dispatched operation')
      assert.equal([...memory.values()].join('\n'), recordBeforeReview, 'active operation identity is unchanged while resolution is denied')
      assert.equal(certificateReads, 1, 'the active owner blocks a competing metadata inspection')
    } finally {
      releasePost?.({ data: { id: 5, label: 'Certificado novo', erp_establishment_id: 6, revision: 1, revision_id: 11, status: 'active' } })
      await upload
    }
    assert.equal(memory.size, 0, 'the original active upload clears its own identity after its response is confirmed')
  })

  it('does not dispatch a certificate POST when authority changes during the baseline read', async (t) => {
    installSessionStorage(t)
    let releaseBaseline!: (value: unknown) => void
    let writes = 0
    const mounted = loadWorkspaceComposable(async (path, options) => {
      if (path === '/erp/fiscal/certificates?per_page=100') {
        return await new Promise((resolve) => {
          releaseBaseline = resolve
        })
      }
      if (path === '/erp/fiscal/certificates' && options?.method === 'POST') writes++
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    const pending = mounted.workspace.uploadCertificate({ label: 'Certificado matriz', establishmentId: 6, certificate, password: '' })
    mounted.identity.value.current_company!.id = 23
    releaseBaseline({ data: [] })
    await pending

    assert.equal(writes, 0, 'the baseline response from company 22 cannot dispatch a certificate mutation in company 23')
  })

  it('disables certificate selection in the mounted workspace while the baseline operation is active', async (t) => {
    installSessionStorage(t)
    let releaseBaseline!: (value: unknown) => void
    const fiscal = loadWorkspaceComposable(async (path, options) => {
      if (path === '/erp/fiscal/certificates?per_page=100' && !options?.method) {
        return await new Promise((resolve) => {
          releaseBaseline = resolve
        })
      }
      if (path === '/erp/fiscal/certificates' && options?.method === 'POST') return { data: { id: 5, label: 'Certificado novo', erp_establishment_id: 6, revision: 1, revision_id: 11, status: 'active' } }
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['erp.fiscal.certificates.index', 'erp.fiscal.certificates.rotate'] })
    fiscal.workspace.selectedEstablishmentId = 6
    fiscal.workspace.establishments = [{ id: 6, name: 'Matriz', cnpj: '11222333000181', status: 'active' }]
    fiscal.workspace.certificates = [{ id: 4, label: 'Certificado anterior', cnpj: '11222333000181', erp_establishment_id: 6, revision: 1, status: 'active', valid_from: null, valid_until: null }]
    fiscal.workspace.load = async () => undefined
    fiscal.workspace.recoverPendingOperations = async () => undefined
    t.after(fiscal.close)
    const identity = Vue.ref({ id: 7, current_company: { id: 22, permissions: ['*'] } })
    const mounted = mountWorkspaceSfc(fiscal.workspace, identity)
    await Vue.nextTick()
    const certificatesTab = findNode(mounted.root, node => node.tag === 'button' && textContent(node).trim() === 'Certificados')
    assert.ok(certificatesTab)
    ;(certificatesTab.props.onClick as () => void)()
    await Vue.nextTick()
    const selection = findNode(mounted.root, node => node.tag === 'button' && textContent(node).trim() === 'Substituir arquivo')
    assert.ok(selection)
    assert.equal(selection.props.disabled, false)
    const pendingUpload = fiscal.workspace.uploadCertificate({ label: 'Certificado novo', establishmentId: 6, certificate, password: '', certificateId: 4 })
    for (let attempt = 0; attempt < 10 && !releaseBaseline; attempt++) await Vue.nextTick()
    assert.ok(releaseBaseline, 'the authorized rotation reaches its deferred baseline request')
    await Vue.nextTick()

    assert.equal(selection.props.disabled, true, 'the operator cannot change the certificate selection while its baseline request is pending')
    releaseBaseline({ data: [{ id: 4, label: 'Certificado anterior', cnpj: '11222333000181', erp_establishment_id: 6, revision: 1, revision_id: 10, status: 'active', certificate_fingerprint: 'old', valid_from: null, valid_until: null }] })
    await pendingUpload
    mounted.app.unmount()
    fiscal.close()
  })

  it('reports an unavailable PDF response without creating or returning a downloaded Blob', async (t) => {
    const mounted = loadWorkspaceComposable(async () => {
      throw { statusCode: 503, data: { message: 'PDF indisponível' } }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    mounted.workspace.selectedDocument = { id: 91, status: 'authorized', artifact_ready: true }
    let downloads = 0
    const previous = globalThis.URL.createObjectURL
    globalThis.URL.createObjectURL = (() => {
      downloads++
      return 'blob:unexpected'
    }) as typeof globalThis.URL.createObjectURL
    t.after(() => {
      globalThis.URL.createObjectURL = previous
    })

    await assert.rejects(mounted.workspace.downloadArtifact({ id: 91, status: 'authorized', artifact_ready: true }, 'pdf'))
    assert.equal(downloads, 0)
    assert.match(mounted.workspace.artifactError, /PDF indisponível/i)
  })

  it('downloads only XML MIME responses and rejects JSON payloads from the XML route', async (t) => {
    const created: Blob[] = []
    const previousCreate = globalThis.URL.createObjectURL
    const previousRevoke = globalThis.URL.revokeObjectURL
    const previousDocument = Object.getOwnPropertyDescriptor(globalThis, 'document')
    globalThis.URL.createObjectURL = ((blob: Blob) => {
      created.push(blob)
      return 'blob:fiscal-xml'
    }) as typeof globalThis.URL.createObjectURL
    globalThis.URL.revokeObjectURL = (() => {}) as typeof globalThis.URL.revokeObjectURL
    Object.defineProperty(globalThis, 'document', { configurable: true, value: { createElement: () => ({ click() {}, href: '', download: '' }) } })
    t.after(() => {
      globalThis.URL.createObjectURL = previousCreate
      globalThis.URL.revokeObjectURL = previousRevoke
      if (previousDocument) Object.defineProperty(globalThis, 'document', previousDocument)
      else Reflect.deleteProperty(globalThis, 'document')
    })

    const xmlMounted = loadWorkspaceComposable(async (path) => {
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, model: '55', environment: 'homologation', status: 'authorized', artifact_ready: true, claims_provider_authorization: true } }
      if (path.endsWith('/xml')) return new Blob(['<nfeProc/>'], { type: 'application/xml' })
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(xmlMounted.close)
    await xmlMounted.workspace.loadDocument(91)
    await xmlMounted.workspace.downloadArtifact(xmlMounted.workspace.selectedDocument, 'xml')
    assert.equal(created.length, 1)
    assert.equal(created[0]?.type, 'application/xml')

    let releaseArtifact!: (value: Blob) => void
    const changingSelection = loadWorkspaceComposable(async (path) => {
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, model: '55', environment: 'homologation', status: 'authorized', artifact_ready: true, claims_provider_authorization: true } }
      if (path === '/erp/fiscal/documents/92') return { data: { id: 92, model: '55', environment: 'homologation', status: 'authorized', artifact_ready: true, claims_provider_authorization: true } }
      return new Promise<Blob>((resolve) => {
        releaseArtifact = resolve
      })
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(changingSelection.close)
    await changingSelection.workspace.loadDocument(91)
    const pendingDownload = changingSelection.workspace.downloadArtifact(changingSelection.workspace.selectedDocument, 'xml')
    await changingSelection.workspace.loadDocument(92)
    releaseArtifact(new Blob(['<nfeProc/>'], { type: 'application/xml' }))
    await pendingDownload
    assert.equal(created.length, 1, 'a stale document response cannot trigger a download after selection changes')

    const jsonMounted = loadWorkspaceComposable(async (path) => {
      if (path === '/erp/fiscal/documents/91') return { data: { id: 91, model: '55', environment: 'homologation', status: 'authorized', artifact_ready: true, claims_provider_authorization: true } }
      if (path.endsWith('/xml')) return new Blob(['{"message":"erro"}'], { type: 'application/json' })
      return { data: [] }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(jsonMounted.close)
    await jsonMounted.workspace.loadDocument(91)
    await assert.rejects(jsonMounted.workspace.downloadArtifact(jsonMounted.workspace.selectedDocument, 'xml'), /XML indisponível/i)
    assert.equal(created.length, 1, 'JSON error payload is never exposed as an XML download')
  })

  it('preserves the complete unsaved model form and maps field-level 422 errors', async (t) => {
    installSessionStorage(t)
    const submitted = {
      erp_establishment_id: 41,
      model: '55',
      document_kind: 'posted_sale',
      erp_sale_id: 73,
      idempotency_key: 'draft-73',
      source: { identity: { purpose: 1 }, recipient: { legal_name: 'Cliente exemplo' }, items: [{ source_line_id: 801, cfop: '' }] }
    }
    const mounted = loadWorkspaceComposable(async () => {
      throw { statusCode: 422, data: { errors: { 'source.items.0.cfop': ['CFOP incompatível com a operação.'], 'source.identity.purpose': ['Finalidade fiscal obrigatória.'] } } }
    }, { actorId: 7, companyId: 22, grants: ['*'] })
    t.after(mounted.close)
    mounted.workspace.documentForm = structuredClone(submitted)

    await assert.rejects(mounted.workspace.createDocumentDraft(submitted))

    assert.deepEqual(mounted.workspace.documentForm, submitted)
    assert.equal(mounted.workspace.fieldErrors['source.items.0.cfop'], 'CFOP incompatível com a operação.')
    assert.equal(mounted.workspace.fieldErrors['source.identity.purpose'], 'Finalidade fiscal obrigatória.')
  })

  it('does not read the selected fiscal workspace without an active company context', async (t) => {
    const calls: string[] = []
    const mounted = loadWorkspaceComposable(async (path) => {
      calls.push(path)
      return { data: [] }
    }, { actorId: 7, companyId: null, grants: [] })
    t.after(mounted.close)

    await mounted.workspace.load(41)

    assert.equal(calls.length, 0)
    assert.equal(mounted.workspace.noCompany, true)
    assert.equal(mounted.workspace.readOnly, true)
  })

  it('limits fiscal writes by company role and active office membership', (t) => {
    const noop = async () => ({ data: [] })
    const viewer = loadWorkspaceComposable(noop, { actorId: 7, companyId: 22, grants: ['erp.fiscal.documents.*'], companyRole: 'viewer' })
    const officeUser = loadWorkspaceComposable(noop, { actorId: 7, companyId: 22, grants: ['erp.fiscal.documents.transmit'], accessKind: 'delegated', accountRole: 'user' })
    const officeAdmin = loadWorkspaceComposable(noop, { actorId: 7, companyId: 22, grants: ['erp.fiscal.documents.transmit'], accessKind: 'delegated', accountRole: 'admin' })
    const wrongOffice = loadWorkspaceComposable(noop, { actorId: 7, companyId: 22, grants: ['erp.fiscal.documents.transmit'], accessKind: 'delegated', accountRole: 'admin', accountId: 9 })
    t.after(viewer.close)
    t.after(officeUser.close)
    t.after(officeAdmin.close)
    t.after(wrongOffice.close)
    assert.equal(viewer.workspace.canTransmit, false)
    assert.equal(officeUser.workspace.canTransmit, false)
    assert.equal(officeAdmin.workspace.canTransmit, true)
    assert.equal(wrongOffice.workspace.canTransmit, false)
  })

  it('ignores a late successful configuration response after the selected company changes', async (t) => {
    const finishes: Array<(value: unknown) => void> = []
    const mounted = loadWorkspaceComposable(async () => await new Promise((resolve) => {
      finishes.push(resolve)
    }), { actorId: 7, companyId: 22, grants: ['erp.fiscal.configuration.show'] })
    t.after(mounted.close)
    const load = mounted.workspace.load(41)
    mounted.identity.value.current_company!.id = 23
    for (const finish of finishes) finish({ data: { establishment: { id: 41, name: 'Matriz' }, release_gates: { provider: 'unavailable', production_transmission_enabled: false } } })
    await load

    assert.equal(mounted.workspace.configuration, null)
    assert.equal(mounted.workspace.establishments?.length ?? 0, 0)
  })

  it('closes a pending confirmation and suppresses a late error after the company context changes in the mounted workspace', async () => {
    let rejectTransmission!: (error: Error) => void
    const document = { id: 91, model: '55', environment: 'homologation', status: 'queued' }
    const identity = Vue.ref({ id: 7, current_company: { id: 22, permissions: ['*'] } })
    const workspace = Vue.reactive({
      selectedEstablishmentId: 6, establishments: [{ id: 6, name: 'Matriz', cnpj: '11222333000181' }],
      documents: [document], selectedDocument: document, selectedSale: null, selectedReturn: null, sales: [], returns: [],
      configuration: { environment: 'homologation', release_gates: { provider: 'ready', production_transmission_enabled: false } },
      loading: false, saving: false, noCompany: false, canRead: true, readOnly: false, canCreateDocuments: false,
      canQueue: true, canTransmit: true, canConsult: true, canEnableContingency: false, canReconcileContingency: false,
      canDownloadXml: true, canDownloadPdf: true, pendingRecovery: null, releaseMessage: '', errorMessage: '',
      runDocumentAction: async () => undefined,
      transmitDocument: () => new Promise((_resolve, reject) => { rejectTransmission = reject }),
      load: async () => undefined, recoverPendingOperations: async () => undefined,
      loadDocument: async () => undefined
    })
    const mounted = mountWorkspaceSfc(workspace, identity)
    await Vue.nextTick()

    const request = findNode(mounted.root, node => node.props['data-action'] === 'request-transmit')
    assert.ok(request)
    ;(request.props.onClick as () => void)()
    await Vue.nextTick()
    const confirm = findNode(mounted.root, node => node.tag === 'button' && textContent(node).trim() === 'Confirmar operação')
    assert.ok(confirm, 'the mounted workspace displays its explicit confirmation step')
    const confirmation = (confirm.props.onClick as () => Promise<void>)()
    identity.value.current_company.id = 23
    await Vue.nextTick()
    rejectTransmission(new Error('late company A failure'))
    await confirmation
    await Vue.nextTick()

    assert.equal(findNode(mounted.root, node => node.props['data-modal'] === 'Confirmar operação fiscal'), undefined)
    assert.equal(textContent(mounted.root).includes('late company A failure'), false, 'the stale error is not shown in company B')
    mounted.app.unmount()
  })
})
