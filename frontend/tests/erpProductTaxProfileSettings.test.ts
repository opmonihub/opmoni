import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { createSSRApp, defineComponent, h } from 'vue'
import * as Vue from 'vue'
import { renderToString } from 'vue/server-renderer'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

type TreeNode = { tag: string, props: Record<string, unknown>, text: string, children: TreeNode[], parent: TreeNode | null }

function walk(root: TreeNode): TreeNode[] {
  return [root, ...root.children.flatMap(walk)]
}

function mountDefaultProfile(options: { initialProfileId: number | null, revision: number, rejectSave?: boolean }) {
  const state = Vue.ref<Record<string, unknown> | null>({ default_product_tax_profile_id: options.initialProfileId, tax_default_revision: options.revision })
  const pending = Vue.ref(false)
  const authorityKey = Vue.ref('account-1:company-41:session-1')
  const canRead = Vue.computed(() => true)
  const canWrite = Vue.computed(() => true)
  const saves: Record<string, unknown>[] = []
  const settings = {
    state, pending, authorityKey, canRead, canWrite,
    refresh: async () => null,
    save: async (patch: Record<string, unknown>) => {
      saves.push(patch)
      if (options.rejectSave)
        throw { status: 409, data: { message: 'A configuração mudou. Atualize e tente novamente.' } }
      state.value = {
        ...state.value,
        default_product_tax_profile_id: patch.default_product_tax_profile_id,
        tax_default_revision: Number(state.value?.tax_default_revision ?? 0) + 1
      }
      return { data: state.value, meta: {} }
    }
  }
  const ReferenceSelect = Vue.defineComponent({
    props: ['modelValue', 'label', 'name', 'error', 'disabled'],
    emits: ['update:modelValue', 'selection-validity'],
    setup(props, { emit }) {
      return () => Vue.h('label', { 'data-reference': props.name }, [
        String(props.label),
        Vue.h('select', {
          name: props.name,
          value: props.modelValue ?? '',
          disabled: props.disabled,
          onChange: (event: Event) => {
            const value = Number((event.target as HTMLSelectElement).value) || null
            emit('update:modelValue', value)
            emit('selection-validity', { id: value, valid: value === null || value === 24 })
          }
        }, [Vue.h('option', { value: '' }, 'Sem vínculo'), Vue.h('option', { value: '24' }, 'Perfil revisado')]),
        props.error ? Vue.h('span', { role: 'alert' }, String(props.error)) : null
      ])
    }
  })
  const component = compileVueComponent('components/erp/ErpCompanyProductTaxProfileDefault.vue', {
    useErpCompanySettings: () => settings,
    apiFailure: (failure: { data?: { message?: string }, status?: number }) => ({
      message: failure.data?.message ?? 'Falha ao salvar.',
      status: failure.status ?? 0,
      errors: {}
    }),
    onMounted: Vue.onMounted,
    onScopeDispose: Vue.onScopeDispose,
    watch: Vue.watch,
    ref: Vue.ref,
    computed: Vue.computed
  }, { '~/components/erp/catalogs/ErpCatalogReferenceSelect.vue': ReferenceSelect })

  const root: TreeNode = { tag: 'root', props: {}, text: '', children: [], parent: null }
  const renderer = Vue.createRenderer<TreeNode, TreeNode>({
    patchProp(node, key, _previous, next) { node.props[key] = next },
    insert(node, parent, anchor = null) {
      if (node.parent) {
        const previous = node.parent.children.indexOf(node)
        if (previous >= 0) node.parent.children.splice(previous, 1)
      }
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index < 0) parent.children.push(node)
      else parent.children.splice(index, 0, node)
    },
    remove(node) {
      if (!node.parent) return
      const index = node.parent.children.indexOf(node)
      if (index >= 0) node.parent.children.splice(index, 1)
      node.parent = null
    },
    createElement: tag => ({ tag, props: {}, text: '', children: [], parent: null }),
    createText: text => ({ tag: '#text', props: {}, text, children: [], parent: null }),
    createComment: text => ({ tag: '#comment', props: {}, text, children: [], parent: null }),
    setText(node, text) { node.text = text },
    setElementText(node, text) {
      node.text = text
      node.children = []
    },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null
  })
  const app = renderer.createApp(component)
  app.component('UPageCard', defineComponent({ setup: (_props, { slots }) => () => Vue.h('article', slots.default?.()) }))
  app.component('UButton', defineComponent({ props: ['label', 'disabled', 'loading'], setup: (props, { attrs }) => () => Vue.h('button', { ...attrs, disabled: props.disabled, onClick: attrs.onClick }, String(props.label ?? '')) }))
  app.component('ErpCatalogReferenceSelect', ReferenceSelect)
  app.mount(root)
  return {
    root,
    state,
    saves,
    unmount: () => app.unmount(),
    async chooseProfile(id: string) {
      const select = walk(root).find(node => node.tag === 'select')
      assert.ok(select)
      ;(select.props.onChange as ((event: Event) => void) | undefined)?.({ target: { value: id } } as unknown as Event)
      await Vue.nextTick()
    },
    async save() {
      const button = walk(root).find(node => node.tag === 'button' && node.text.includes('Salvar perfil padrão'))
      assert.ok(button)
      assert.notEqual(button.props.disabled, true, 'a validated profile choice should enable saving')
      const handler = button.props.onClick as (() => unknown) | undefined
      await handler?.()
      await Vue.nextTick()
    },
    text: () => walk(root).map(node => node.text).join(' '),
    selected: () => walk(root).find(node => node.tag === 'select')?.props.value
  }
}

describe('ERP company product tax profile default settings integration', () => {
  it('mounts the isolated default-profile control while preserving initialization reconciliation', async () => {
    const page = compileVueComponent('pages/erp/configuracoes/empresa.vue', { definePageMeta() {} }, {
      '~/utils/erpCompanySettingsFields': { erpSettingsBusinessFields: [] }
    })
    const app = createSSRApp(page)
    const named = (name: string) => defineComponent({ setup: () => () => h('div', { 'data-component': name }) })
    app.component('ErpSettingsSectionForm', named('settings'))
    app.component('ErpCompanyInitializationReconciliation', named('initialization'))
    app.component('ErpCompanyProductTaxProfileDefault', named('product-tax-profile-default'))

    const html = await renderToString(app)
    const initializationIndex = html.indexOf('data-component="initialization"')
    const profileIndex = html.indexOf('data-component="product-tax-profile-default"')
    const settingsIndex = html.indexOf('data-component="settings"')
    assert.ok(initializationIndex >= 0)
    assert.ok(profileIndex > initializationIndex, 'profile setup follows existing initialization reconciliation')
    assert.ok(settingsIndex > profileIndex, 'regular company settings remain mounted after the new setup')
  })

  it('saves the selected profile with the current expected revision', async (t) => {
    const form = mountDefaultProfile({ initialProfileId: null, revision: 5 })
    t.after(form.unmount)
    await form.chooseProfile('24')
    await form.save()
    assert.deepEqual(form.saves, [{ default_product_tax_profile_id: 24, expected_tax_default_revision: 5 }])
    assert.equal(form.state.value?.tax_default_revision, 6)
  })

  it('preserves the draft profile choice and reports a stale revision conflict', async (t) => {
    const form = mountDefaultProfile({ initialProfileId: null, revision: 8, rejectSave: true })
    t.after(form.unmount)
    await form.chooseProfile('24')
    await form.save()
    assert.equal(form.selected(), 24)
    assert.match(form.text(), /A configuração mudou/)
    assert.deepEqual(form.saves, [{ default_product_tax_profile_id: 24, expected_tax_default_revision: 8 }])
  })
})
