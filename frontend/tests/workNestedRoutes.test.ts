import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import { parse } from 'vue/compiler-sfc'

// Nuxt's route generator is private in the installed package. Guard the real
// filesystem contract instead: index and [id] must be sibling pages, without a
// section parent that keeps the list's setup/fetches alive on detail routes.
describe('Work list and detail route isolation', () => {
  for (const section of ['modelos', 'processos']) {
    it(`keeps ${section} list and detail as independent page entrypoints`, () => {
      const parent = new URL(`../app/pages/work/${section}.vue`, import.meta.url)
      const index = new URL(`../app/pages/work/${section}/index.vue`, import.meta.url)
      const detail = new URL(`../app/pages/work/${section}/[id].vue`, import.meta.url)

      assert.equal(existsSync(parent), false, `${section}.vue must not intercept detail routes`)
      assert.ok(existsSync(index), `${section}/index.vue must serve the unchanged list URL`)
      assert.ok(existsSync(detail), `${section}/[id].vue must serve detail and editor URLs`)

      const source = readFileSync(index, 'utf8')
      const { descriptor, errors } = parse(source)
      assert.deepEqual(errors, [])
      assert.ok(descriptor.scriptSetup)
      assert.ok(descriptor.template)
      assert.match(descriptor.scriptSetup.content, /definePageMeta\(\{ middleware: 'auth' \}\)/)
      assert.doesNotMatch(descriptor.template.content, /<NuxtPage\b/)
      assert.doesNotMatch(source, /route\.path/)
    })
  }
})
