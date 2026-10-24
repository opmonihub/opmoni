import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { it } from 'node:test'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import * as Vue from 'vue'
import { renderToString } from 'vue/server-renderer'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

async function resolveSettingsRoot(company: Record<string, unknown> | null) {
  const source = readFileSync(new URL('../app/pages/erp/configuracoes/index.vue', import.meta.url), 'utf8')
  const script = compileScript(parse(source).descriptor, { id: 'settings-root' })
  const code = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const exports: Record<string, unknown> = {}
  const destinations: Array<{ to: string, replace?: boolean }> = []
  const execute = new Function('require', 'exports', 'definePageMeta', 'useAuth', 'navigateTo', code)
  const requireModule = (path: string) => {
    if (path === 'vue') return Vue
    if (path === '~/utils/erpWorkspaceNav') return loadAppComposable('utils/erpWorkspaceNav.ts')
    throw new Error(`Unexpected import ${path}`)
  }
  execute(requireModule, exports, () => {}, () => ({ currentCompany: Vue.ref(company), resolved: Vue.ref(true) }),
    async (to: string, options?: { replace?: boolean }) => { destinations.push({ to, ...options }) })
  const component = exports.default as Vue.Component
  await renderToString(Vue.createSSRApp({ ...component, render: () => null }))
  return destinations
}

it('routes an establishments-only settings root to its first permitted child', async () => {
  const destinations = await resolveSettingsRoot({ id: 7, status: 'active', role: 'viewer', access_kind: 'direct', permissions: ['erp.establishments.index'] })
  assert.deepEqual(destinations, [{ to: '/erp/configuracoes/estabelecimentos', replace: true }])
})

it('does not grant Empresa from a settings root with no authorized child', async () => {
  const destinations = await resolveSettingsRoot({ id: 7, status: 'active', role: 'viewer', access_kind: 'direct', permissions: [] })
  assert.equal(destinations.some(destination => destination.to === '/erp/configuracoes/empresa'), false)
})
