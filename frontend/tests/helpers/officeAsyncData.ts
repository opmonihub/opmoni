import { readFileSync } from 'node:fs'
import * as Vue from 'vue'
import ts from 'typescript'

export type OfficePayload = { serverRendered: boolean, data: Record<string, unknown>, _errors: Record<string, unknown> }

// Run the installed Nuxt engine, replacing only its Nuxt-app/build environment.
// This covers useAsyncData's null/error cache and unmount/reentry semantics;
// actual document hydration remains a separate browser acceptance gate.
export function officeAsyncDataEngine(server: boolean, payload: OfficePayload) {
  const app = {
    payload: Vue.reactive(payload),
    isHydrating: !server,
    static: { data: {} },
    _asyncData: {},
    _asyncDataPromises: {},
    hook: () => () => {},
    runWithContext: (run: () => unknown) => run()
  }
  const source = readFileSync(new URL('../../node_modules/nuxt/dist/app/composables/asyncData.js', import.meta.url), 'utf8')
    .replaceAll('import.meta.server', String(server))
    .replaceAll('import.meta.client', String(!server))
    .replaceAll('import.meta.dev', 'false')
    .replaceAll('import.meta.prerender', 'false')
  const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const exports: Record<string, unknown> = {}
  new Function('require', 'exports', code)((path: string) => {
    if (path === 'vue') return Vue
    if (path.endsWith('/nuxt.js')) return { useNuxtApp: () => app }
    if (path.endsWith('/error.js')) return { createError: (error: unknown) => error }
    if (path.endsWith('/debounce-tick.js')) return { debounceTick: (run: unknown) => run }
    if (path.endsWith('/compiler/runtime/index.js')) return { defineKeyedFunctionFactory: (config: { factory: unknown }) => config.factory }
    if (path === '#build/nuxt.config.mjs') return { asyncDataDefaults: { deep: false }, granularCachedData: true, pendingWhenIdle: false, purgeCachedData: true, stripNeverHydratedData: false, vapor: false }
    return {}
  }, exports)
  return { app, useAsyncData: exports.useAsyncData }
}
