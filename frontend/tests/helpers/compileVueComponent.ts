import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { compileScript, parse } from 'vue/compiler-sfc'
import * as vue from 'vue'
import ts from 'typescript'

const require = createRequire(import.meta.url)

export function vueSource(path: string): string {
  return readFileSync(new URL(`../../app/${path}`, import.meta.url), 'utf8')
}

/** Compile the real SFC without a browser, Nuxt server, or new test dependencies. */
export function compileVueComponent(path: string, bindings: Record<string, unknown> = {}): vue.Component {
  const source = vueSource(path)
  const { descriptor } = parse(source, { filename: path })
  const script = compileScript(descriptor, { id: path, inlineTemplate: true })
  const compiled = ts.transpileModule(script.content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
  })
  const module = { exports: {} as { default: vue.Component } }
  const context = { computed: vue.computed, ...bindings }
  const execute = new Function('require', 'module', 'exports', ...Object.keys(context), compiled.outputText)
  const resolveImport = (specifier: string) => require(specifier.startsWith('~/')
    ? fileURLToPath(new URL(`../../app/${specifier.slice(2)}.ts`, import.meta.url))
    : specifier)
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
