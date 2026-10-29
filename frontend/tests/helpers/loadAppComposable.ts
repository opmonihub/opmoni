import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, extname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'

const require = createRequire(import.meta.url)
const appRoot = fileURLToPath(new URL('../../app/', import.meta.url))
const sharedRoot = fileURLToPath(new URL('../../shared/', import.meta.url))

/** Load real Nuxt auto-imported composables with only their app boundaries injected. */
export function loadAppComposable<T extends Record<string, unknown>>(
  path: string,
  bindings: Record<string, unknown> = {}
): T {
  // Nuxt supplies a route even when a test exercises a canonical, queryless entry.
  bindings = { useRoute: () => ({ query: {} }), ...bindings }
  const modules = new Map<string, { exports: Record<string, unknown> }>()

  function resolveFile(from: string, specifier: string): string {
    let base: string
    if (specifier.startsWith('#shared/'))
      base = resolve(sharedRoot, specifier.slice('#shared/'.length))
    else if (specifier.startsWith('~/'))
      base = resolve(appRoot, specifier.slice(2))
    else if (specifier.startsWith('.'))
      base = resolve(dirname(from), specifier)
    else
      return specifier

    if (extname(base))
      return base
    for (const extension of ['.ts', '.js']) {
      const candidate = `${base}${extension}`
      try {
        readFileSync(candidate)
        return candidate
      } catch {
        // Try the next source extension.
      }
    }
    throw new Error(`Could not resolve ${specifier} from ${from}`)
  }

  function compile(filename: string): Record<string, unknown> {
    const existing = modules.get(filename)
    if (existing)
      return existing.exports

    const module = { exports: {} as Record<string, unknown> }
    modules.set(filename, module)
    const source = readFileSync(filename, 'utf8')
    // Nuxt replaces these build-time flags; composable unit tests run in the
    // server context unless they explicitly use the SFC client harness.
    const normalized = source.replaceAll('import.meta.client', 'false').replaceAll('import.meta.server', 'true')
    const compiled = ts.transpileModule(normalized, {
      compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
    }).outputText
    const localRequire = (dependency: string) => load(filename, dependency)
    const execute = new Function('require', 'module', 'exports', ...Object.keys(bindings), compiled)
    execute(localRequire, module, module.exports, ...Object.values(bindings))
    return module.exports
  }

  function load(from: string, specifier: string): unknown {
    const filename = resolveFile(from, specifier)
    if (filename === specifier)
      return require(specifier)
    return compile(filename)
  }

  return compile(resolve(appRoot, path)) as T
}
