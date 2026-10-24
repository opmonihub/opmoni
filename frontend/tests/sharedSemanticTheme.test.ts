import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { readFileSync } from 'node:fs'
import { it } from 'node:test'
import ts from 'typescript'

const require = createRequire(import.meta.url)
const { tv } = require('../node_modules/.pnpm/node_modules/tailwind-variants')

const evaluate = (source: string) => {
  const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const exports = {} as { default: Record<string, unknown> }
  new Function('exports', 'defineAppConfig', compiled)(exports, (config: unknown) => config)
  return exports.default
}
const config = evaluate(readFileSync(new URL('../app/app.config.ts', import.meta.url), 'utf8')) as { ui: { button?: object } }
const theme = evaluate(readFileSync(new URL('../.nuxt/ui/button.ts', import.meta.url), 'utf8'))
const button = tv({ extend: theme, ...config.ui.button })

it('retains readable solid semantic actions in hover/active states through the native shared theme', () => {
  for (const color of ['primary', 'success']) {
    const classes: string = button({ color, variant: 'solid' }).base()
    assert.ok(classes.includes(`hover:bg-${color}/90`), `${color} hover retains a readable shared semantic background`)
    assert.ok(classes.includes(`active:bg-${color}/90`))
    assert.ok(classes.includes('dark:hover:bg-') || classes.includes('dark:hover:'), 'dark brightness uses the native existing contrast pair')
    assert.ok(classes.includes('disabled:cursor-not-allowed'))
    assert.ok(classes.includes('focus-visible:outline-3'))
  }
})

it('keeps neutral/destructive action variants and sizes intact', () => {
  const neutral: string = button({ color: 'neutral', variant: 'outline', size: 'md' }).base()
  const destructive: string = button({ color: 'error', variant: 'solid', size: 'md' }).base()
  assert.ok(neutral.includes('text-default'))
  assert.ok(destructive.includes('bg-error'))
  assert.ok(destructive.includes('hover:bg-error/75'))
  assert.ok(neutral.includes('px-2.5') && destructive.includes('px-2.5'))
})
