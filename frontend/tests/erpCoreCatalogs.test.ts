import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import type { NavigationMenuItem } from '@nuxt/ui'
import type { CompanyMembership } from '../shared/types/access.ts'
import { loadAppComposable } from './helpers/loadAppComposable.ts'
import {
  erpCadastrosPages,
  erpCatalogRoutes,
  formatErpDecimal
} from '../shared/utils/erpCatalogs.ts'
import {
  sampleErpProductFixture,
  samplePostedLineSnapshot
} from '../shared/fixtures/erpCoreCatalogs.ts'

describe('ERP core catalogs shared contracts', () => {
  it('freezes catalog API route families', () => {
    assert.equal(erpCatalogRoutes.contacts, '/erp/contacts')
    assert.equal(erpCatalogRoutes.taxRates, '/erp/tax-settings/tax-rates')
    assert.equal(erpCatalogRoutes.importProductsConfirm, '/erp/catalog/import/products/confirm')
  })

  it('maps cadastros Nuxt pages', () => {
    assert.equal(erpCadastrosPages.contacts, '/erp/cadastros/contatos')
    assert.equal(erpCadastrosPages.logistics, '/erp/cadastros/logistica')
  })

  it('preserves decimal strings without float coercion', () => {
    assert.equal(formatErpDecimal('10.5000'), '10.5000')
    assert.equal(formatErpDecimal(10.5), '10.5')
  })

  it('posted snapshot stays stable when catalog price changes', () => {
    const edited = { ...sampleErpProductFixture, sale_price: '99.0000' }
    assert.notEqual(edited.sale_price, samplePostedLineSnapshot.unit_price)
    assert.equal(samplePostedLineSnapshot.unit_price, '10.5000')
  })

  it('cadastros persistent parent references authorized catalog routes', async () => {
    const src = await readFile(new URL('../app/pages/erp/cadastros.vue', import.meta.url), 'utf8')
    const { descriptor } = parse(src)
    const script = compileScript(descriptor, { id: 'catalog-hub' })
    const template = compileTemplate({
      source: descriptor.template!.content, filename: 'index.vue', id: 'catalog-hub',
      compilerOptions: { bindingMetadata: script.bindings }
    })
    assert.deepEqual(template.errors, [])
    const currentCompany = Vue.ref<CompanyMembership | null>({
      id: 1, name: 'Empresa teste', status: 'active', access_kind: 'direct',
      role: 'operator', permissions: ['erp.contacts.index'], delegated_office_id: null
    })
    const globals = {
      computed: Vue.computed, definePageMeta() {}, useHead() {},
      useRoute: () => ({ path: '/erp/cadastros' }),
      useAuth: () => ({ currentCompany })
    }
    const workspace = loadAppComposable('utils/erpWorkspaceNav.ts')
    function evaluate(code: string) {
      const output = ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
      const exports = {} as { default: { setup(props: object, context: object): object }, render: (...args: unknown[]) => Vue.VNode }
      new Function('require', 'exports', ...Object.keys(globals), output)((name: string) => {
        if (name === 'vue') return { ...Vue, resolveComponent: (name: string) => ({ name }) }
        assert.equal(name, '~/utils/erpWorkspaceNav')
        return workspace
      }, exports, ...Object.values(globals))
      return exports
    }
    const scope = Vue.effectScope()
    try {
      const state = scope.run(() => evaluate(script.content).default.setup({}, { expose() {} }))!
      const render = evaluate(template.code).render
      const nodes = (node: Vue.VNode): Vue.VNode[] => {
        const children = Array.isArray(node.children)
          ? node.children
          : node.children && typeof node.children === 'object'
            ? Object.values(node.children).filter(value => typeof value === 'function').flatMap(slot => slot())
            : []
        return [node, ...children.filter(Vue.isVNode).flatMap(nodes)]
      }
      const rendered = () => nodes(render({ $slots: {} }, [], {}, Vue.proxyRefs(state), {}, {}))
      const destinations = (): string[] => {
        const menu = rendered().find(node => (node.type as { name?: string }).name === 'ErpCatalogPageShell')
        assert.ok(menu, 'the actual page passes its computed registry groups to the menu')
        const flatten = (items: NavigationMenuItem[]): NavigationMenuItem[] => items.flatMap(item => [item, ...flatten(item.children ?? [])])
        return flatten((menu.props!.navigation as NavigationMenuItem[][]).flat()).flatMap(item => typeof item.to === 'string' ? [item.to] : [])
      }
      assert.deepEqual(destinations(), [erpCadastrosPages.contacts])
      currentCompany.value.permissions = ['erp.products.index']
      assert.ok(!destinations().includes(erpCadastrosPages.contacts), 'the page follows current permission filtering')
      currentCompany.value = null
      assert.deepEqual(destinations(), [])
    } finally {
      scope.stop()
    }
  })
})
