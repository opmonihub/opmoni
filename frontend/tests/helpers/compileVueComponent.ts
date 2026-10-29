import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { compileScript, parse } from 'vue/compiler-sfc'
import * as vue from 'vue'
import ts from 'typescript'
import { loadAppComposable } from './loadAppComposable.ts'

const require = createRequire(import.meta.url)

export interface VueHostNode {
  tag?: string
  text?: string
  props: Record<string, unknown>
  children: VueHostNode[]
  parent: VueHostNode | null
}

export function vueSource(path: string): string {
  return readFileSync(new URL(`../../app/${path}`, import.meta.url), 'utf8')
}

/** Compile the real SFC without a browser, Nuxt server, or new test dependencies. */
export function compileVueComponent(
  path: string,
  bindings: Record<string, unknown> = {},
  moduleMocks: Record<string, unknown> = {},
  runtime: { client?: boolean, server?: boolean } = {}
): vue.Component {
  const source = vueSource(path)
  const { descriptor } = parse(source, { filename: path })
  const script = compileScript(descriptor, { id: path, inlineTemplate: true })
  const normalizedScript = script.content.replaceAll('import.meta.client', String(runtime.client ?? false)).replaceAll('import.meta.server', String(runtime.server ?? false))
  const compiled = ts.transpileModule(normalizedScript, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
  })
  const module = { exports: {} as { default: vue.Component } }
  const context = {
    computed: vue.computed,
    onScopeDispose: vue.onScopeDispose,
    reactive: vue.reactive,
    ref: vue.ref,
    watch: vue.watch,
    ...bindings
  }
  const execute = new Function('require', 'module', 'exports', ...Object.keys(context), compiled.outputText)
  const asDefaultModule = (value: unknown) => {
    if (value && typeof value === 'object' && '__esModule' in value)
      return value
    return { __esModule: true, default: value }
  }
  const resolveImport = (specifier: string) => {
    if (Object.hasOwn(moduleMocks, specifier))
      return specifier.endsWith('.vue') ? asDefaultModule(moduleMocks[specifier]) : moduleMocks[specifier]
    if (specifier.startsWith('~/')) {
      const modulePath = specifier.slice(2)
      if (modulePath.endsWith('.vue'))
        return asDefaultModule(compileVueComponent(modulePath, bindings, moduleMocks, runtime))
      const sourcePath = modulePath.endsWith('.ts') ? modulePath : `${modulePath}.ts`
      return loadAppComposable(sourcePath, bindings)
    }
    if (specifier.startsWith('#shared/')) {
      const modulePath = specifier.slice('#shared/'.length)
      const sourcePath = modulePath.endsWith('.ts') ? modulePath : `${modulePath}.ts`
      return require(fileURLToPath(new URL(`../../shared/${sourcePath}`, import.meta.url)))
    }
    return require(specifier)
  }
  execute(resolveImport, module, module.exports, ...Object.values(context))
  return module.exports.default
}

/** Mount an actual compiled SFC in Vue's custom renderer, with lightweight Nuxt UI component stubs. */
export function mountVueComponent(component: vue.Component, customComponents: Record<string, vue.Component> = {}, initialProps: Record<string, unknown> = {}) {
  const root: VueHostNode = { props: {}, children: [], parent: null }
  const renderer = vue.createRenderer<VueHostNode, VueHostNode>({
    createElement: tag => ({ tag, props: {}, children: [], parent: null }),
    createText: text => ({ text, props: {}, children: [], parent: null }),
    createComment: text => ({ text, props: {}, children: [], parent: null }),
    setText: (node, text) => { node.text = text },
    setElementText: (node, text) => {
      node.text = text
      node.children = []
    },
    patchProp: (node, key, _previous, next) => { node.props[key] = next },
    insert: (node, parent, anchor) => {
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      parent.children.splice(index < 0 ? parent.children.length : index, 0, node)
    },
    remove: (node) => {
      if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1)
      node.parent = null
    },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null
  })
  const app = renderer.createApp(component, initialProps)
  const passthrough = (name: string): vue.Component => ({
    setup(_props, context) {
      return () => vue.h('div', { 'data-component': name }, Object.values(context.slots).map(slot => slot()))
    }
  })
  app.component('UButton', {
    inheritAttrs: false,
    props: ['disabled', 'type'],
    setup(props, context) {
      return () => vue.h('button', { ...context.attrs, disabled: props.disabled, type: props.type, onClick: props.onClick ?? context.attrs.onClick }, context.slots.default?.())
    }
  })
  app.component('UInput', {
    inheritAttrs: false,
    emits: ['update:modelValue'],
    props: ['type', 'modelValue', 'disabled'],
    setup(props, context) {
      return () => vue.h('input', { ...context.attrs, type: props.type ?? 'text', value: props.modelValue ?? '', disabled: props.disabled, onInput: (event: Event) => context.emit('update:modelValue', (event.target as HTMLInputElement).value) })
    }
  })
  app.component('UCheckbox', {
    inheritAttrs: false,
    props: ['modelValue', 'disabled'],
    setup(props, context) {
      return () => vue.h('button', { ...context.attrs, 'type': 'button', 'role': 'checkbox', 'aria-checked': String(props.modelValue ?? false), 'disabled': props.disabled, 'onClick': () => ((props['onUpdate:modelValue'] ?? context.attrs['onUpdate:modelValue']) as ((value: boolean) => void) | undefined)?.(!props.modelValue) }, context.attrs['aria-label'] ?? 'checkbox')
    }
  })
  app.component('UForm', {
    inheritAttrs: false,
    props: ['state'],
    setup(props, context) {
      return () => vue.h('form', { ...context.attrs, onSubmit: (event: Event) => {
        event.preventDefault()
        ;(context.attrs.onSubmit as ((event: { data: unknown, preventDefault(): void }) => void) | undefined)?.({ data: props.state, preventDefault: () => {} })
      } }, context.slots.default?.())
    }
  })
  app.component('UFormField', {
    props: ['label', 'name', 'error'],
    setup(props, context) {
      return () => vue.h('div', { 'data-field': props.name }, [String(props.label ?? ''), context.slots.default?.(), String(props.error ?? '')])
    }
  })
  app.component('USelect', {
    props: ['items', 'modelValue', 'disabled'],
    setup(props, context) {
      return () => vue.h('select', { ...context.attrs, value: props.modelValue, disabled: props.disabled, onChange: (event: Event) => (context.attrs['onUpdate:modelValue'] as ((value: unknown) => void) | undefined)?.((event.target as HTMLSelectElement).value) }, (props.items as Array<{ label: string, value: unknown }> ?? []).map(item => vue.h('option', { value: item.value }, item.label)))
    }
  })
  app.component('UCheckboxGroup', {
    props: ['items', 'modelValue'],
    setup(props, context) {
      return () => vue.h('fieldset', { 'data-items': JSON.stringify(props.items ?? []) }, [JSON.stringify((props.items as Array<{ label: string }> ?? []).map(item => item.label)), context.slots.default?.()])
    }
  })
  app.component('UAlert', {
    inheritAttrs: false,
    props: ['title', 'description', 'color'],
    setup(props, context) {
      return () => vue.h('aside', { role: 'alert', title: props.title }, [String(props.title ?? ''), String(props.description ?? ''), context.slots.actions?.()])
    }
  })
  if (!Object.hasOwn(customComponents, 'OfficeErpDataTable')) app.component('OfficeErpDataTable', {
    props: ['data'],
    setup(props, context) {
      return () => vue.h('table', {}, [context.slots['select-header']?.(), ...(props.data as unknown[] ?? []).map(row => context.slots['select-cell']?.({ row: { original: row } }))])
    }
  })
  for (const name of ['UModal', 'USelectMenu', 'UPagination', 'UBadge', 'USkeleton', 'UPageCard', 'UFieldGroup', 'OfficeErpPanel', 'OfficeErpPagination', 'NuxtPage'])
    if (!Object.hasOwn(customComponents, name)) app.component(name, passthrough(name))
  for (const [name, child] of Object.entries(customComponents)) app.component(name, child)
  app.mount(root)
  const nodes = (node: VueHostNode, predicate: (candidate: VueHostNode) => boolean): VueHostNode[] => [...(predicate(node) ? [node] : []), ...node.children.flatMap(child => nodes(child, predicate))]
  const text = (node: VueHostNode): string => `${node.text ?? ''}${node.children.map(text).join('')}`
  return { app, root, nodes: (predicate: (candidate: VueHostNode) => boolean) => nodes(root, predicate), text: () => text(root), unmount: () => app.unmount() }
}

/** Exercise actual page computed declarations while leaving unrelated data fetching alone. */
export function evaluateVueBindings(
  source: string,
  names: string[],
  context: Record<string, unknown>
): Record<string, unknown> {
  const { descriptor } = parse(source)
  const script = ts.createSourceFile('page.ts', descriptor.scriptSetup!.content, ts.ScriptTarget.Latest, true)
  const declarations = names.map((name) => {
    const statement = script.statements.find(statement =>
      ts.isVariableStatement(statement)
      && statement.declarationList.declarations.some(declaration =>
        ts.isIdentifier(declaration.name) && declaration.name.text === name
      )
    )
    assert.ok(statement, `Missing page binding: ${name}`)
    return statement.getText(script)
  })
  const compiled = ts.transpileModule(`${declarations.join('\n')}\nreturn { ${names.join(', ')} }`, {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext }
  })
  return new Function(...Object.keys(context), compiled.outputText)(...Object.values(context))
}
