import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { compileScript, parse } from 'vue/compiler-sfc'
import * as vue from 'vue'
import ts from 'typescript'
import { loadAppComposable } from './loadAppComposable.ts'

const require = createRequire(import.meta.url)

export function vueSource(path: string): string {
  return readFileSync(new URL(`../../app/${path}`, import.meta.url), 'utf8')
}

/** Compile the real SFC without a browser, Nuxt server, or new test dependencies. */
export function compileVueComponent(
  path: string,
  bindings: Record<string, unknown> = {},
  moduleMocks: Record<string, unknown> = {}
): vue.Component {
  const source = vueSource(path)
  const { descriptor } = parse(source, { filename: path })
  const script = compileScript(descriptor, { id: path, inlineTemplate: true })
  const compiled = ts.transpileModule(script.content, {
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
        return asDefaultModule(compileVueComponent(modulePath, bindings, moduleMocks))
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
