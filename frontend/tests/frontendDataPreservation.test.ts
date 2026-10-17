import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import type { ZodType } from 'zod'
import type { MonitoringAssessmentPeriod, SerproConnectionMetadata } from '../app/types/serpro.ts'
import type { WorkTemplate, WorkTemplatePayload } from '../app/types/work.ts'
import { whatsappGuideTarget } from '../app/utils/whatsappDispatch.ts'
import { vueSource } from './helpers/compileVueComponent.ts'

const require = createRequire(import.meta.url)

/** Run the real SFC setup with Vue reactivity and local-only service boundaries. */
async function setupPage<T>(path: string, bindings: Record<string, unknown>, props = {}): Promise<T> {
  const { descriptor } = parse(vueSource(path), { filename: path })
  const script = compileScript(descriptor, { id: path })
  const compiled = ts.transpileModule(script.content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
  }).outputText
  const globals = {
    ref: Vue.ref,
    reactive: Vue.reactive,
    computed: Vue.computed,
    watch: Vue.watch,
    definePageMeta() {},
    useToast: () => ({ add() {} }),
    useRetryableLoad: () => ({ isLoading: Vue.ref(false), showError: Vue.ref(false), retry() {} }),
    useAsyncData: async (_key: unknown, load: () => Promise<unknown>, options?: { immediate?: boolean, default?: () => unknown }) => ({
      data: Vue.ref(options?.immediate === false ? options.default?.() : await load()),
      status: Vue.ref('success'),
      error: Vue.ref(null),
      refresh: async () => {}
    }),
    ...bindings
  }
  const exports: Record<string, unknown> = {}
  const resolveImport = (name: string) => {
    if (name === 'vue') return { ...Vue, withAsyncContext: (run: () => unknown) => [run(), () => {}] }
    return require(name.startsWith('~/')
      ? fileURLToPath(new URL(`../app/${name.slice(2)}.ts`, import.meta.url))
      : name)
  }
  new Function('require', 'exports', ...Object.keys(globals), compiled)(resolveImport, exports, ...Object.values(globals))
  const component = exports.default as { setup: (props: object, context: object) => Promise<T> }
  return await component.setup(props, { expose() {}, emit() {} })
}

const template: WorkTemplate = {
  id: 8,
  name: 'Modelo original',
  description: null,
  cascade: false,
  generate_day: 1,
  due_day: 20,
  is_active: true,
  regimes: [],
  steps: [{
    id: 12,
    title: 'Apurar',
    description: '  Instruções preservadas\ncom detalhes.  ',
    department_id: null,
    department: null,
    due_day: 20,
    priority: 'medium',
    order: 1,
    default_assignee_member_id: null
  }]
}

interface TemplatePage {
  form: { name: string, steps: Array<{ title: string, description: string | null }> }
  buildPayload: () => WorkTemplatePayload
  onSave: () => Promise<void>
  syncFromTemplate: (value: WorkTemplate) => void
  addStep: () => void
}

async function templatePage(isNew = false) {
  const writes: WorkTemplatePayload[] = []
  const page = await setupPage<TemplatePage>('pages/work/modelos/[id].vue', {
    useRoute: () => ({ params: { id: isNew ? 'novo' : '8' } }),
    useRouter: () => ({ push: async () => {} }),
    useAuth: () => ({ canManageWork: Vue.ref(true) }),
    useNuxtApp: () => ({ $api: async () => [] }),
    useState: (_key: string, initial: () => unknown) => Vue.ref(initial()),
    useDirectory: () => ({ memberOptions: Vue.ref([]) }),
    useDepartments: () => ({ list: async () => [] }),
    useWork: () => ({
      showTemplate: async () => template,
      previewTemplate: async () => [],
      updateTemplate: async (_id: number, payload: WorkTemplatePayload) => {
        writes.push(payload)
        return template
      }
    })
  })
  return { page, writes }
}

describe('model template step descriptions', () => {
  it('preserves the API description verbatim when saving a renamed model', async () => {
    const { page, writes } = await templatePage()
    page.form.name = 'Modelo renomeado'
    await page.onSave()
    assert.equal(writes.length, 1)
    assert.equal(writes[0]?.name, 'Modelo renomeado')
    assert.equal(writes[0]?.steps?.[0]?.description, template.steps![0]!.description)
  })

  for (const description of [null, '']) {
    it(`keeps the nullable description ${JSON.stringify(description)} through sync and payload`, async () => {
      const { page } = await templatePage()
      page.syncFromTemplate({ ...template, steps: [{ ...template.steps![0]!, description }] })
      assert.equal(page.form.steps[0]?.description, description)
      assert.equal(page.buildPayload().steps?.[0]?.description, description)
    })
  }

  it('initializes both initial and subsequently added steps with null descriptions', async () => {
    const { page } = await templatePage(true)
    assert.equal(page.form.steps.length, 2)
    page.addStep()
    for (const step of page.form.steps) {
      assert.equal(step.description, null)
      step.title = 'Etapa nova'
    }
    assert.deepEqual(page.buildPayload().steps?.map(step => step.description), [null, null, null])
  })
})

interface CredentialFields { consumer_key?: string, consumer_secret?: string, password?: string }
interface SerproPage {
  schema: ZodType<CredentialFields>
  state: CredentialFields
  metadata: Vue.Ref<SerproConnectionMetadata | null>
  startEditing: () => void
  onSubmit: (event: { data: CredentialFields }) => Promise<void>
}

async function serproPage(configured: boolean | null) {
  const metadata = configured === null ? null : { configured, consumer_key_hint: '••••1234' }
  const writes: CredentialFields[] = []
  const page = await setupPage<SerproPage>('pages/admin/serpro.vue', {
    useSerpro: () => ({
      connection: async () => metadata,
      saveConnection: async (payload: CredentialFields) => {
        writes.push(payload)
        return metadata
      },
      testConnectivity: async () => { throw new Error('Provider calls are forbidden in these regressions') }
    })
  })
  return { page, writes }
}

describe('SERPRO write-only credential editing', () => {
  for (const consumerKey of [undefined, '']) {
    it(`accepts a secret-only update with key ${JSON.stringify(consumerKey)} when already configured`, async () => {
      const { page, writes } = await serproPage(true)
      page.startEditing()
      assert.equal(page.state.consumer_key, '', 'Never hydrate the key from its hint or a stored secret')
      const input: CredentialFields = { consumer_secret: 'replacement-secret' }
      if (consumerKey !== undefined) input.consumer_key = consumerKey
      const result = page.schema.safeParse(input)
      assert.equal(result.success, true)
      if (!result.success) return
      await page.onSubmit({ data: result.data })
      assert.equal(writes.length, 1)
      assert.equal(writes[0]?.consumer_key, '')
      assert.equal(writes[0]?.consumer_secret, 'replacement-secret')
      assert.equal(page.state.consumer_key, '')
      assert.equal(page.state.consumer_secret, '')
    })
  }

  for (const configured of [false, null]) {
    for (const consumerKey of [undefined, '']) {
      it(`requires the initial key for metadata ${configured} and key ${JSON.stringify(consumerKey)}`, async () => {
        const { page, writes } = await serproPage(configured)
        const result = page.schema.safeParse({ consumer_key: consumerKey, consumer_secret: 'new-secret' })
        assert.equal(result.success, false)
        if (!result.success) assert.ok(result.error.issues.some(issue => issue.path[0] === 'consumer_key'))
        assert.deepEqual(writes, [])
      })
    }
  }

  it('accepts a supplied initial key and reevaluates validation after configuration', async () => {
    const { page } = await serproPage(false)
    assert.equal(page.schema.safeParse({ consumer_key: 'new-key' }).success, true)
    page.metadata.value = { configured: true } as SerproConnectionMetadata
    assert.equal(page.schema.safeParse({ consumer_secret: 'rotated-secret' }).success, true)
  })
})

describe('declaration detail guide availability', () => {
  async function modal() {
    return await setupPage<{
      canDownload: (artifact: 'guia' | 'recibo' | 'declaracao', period: MonitoringAssessmentPeriod | null) => boolean
    }>('components/monitoring/DeclarationDetailModal.vue', {
      useAuth: () => ({ canManageClients: Vue.ref(true) }),
      useSerpro: () => ({
        declarationDetail: async () => ({ periods: [] }),
        downloadDeclarationArtifact: async () => { throw new Error('Provider downloads are forbidden in these regressions') }
      })
    }, { open: true, obligation: { slug: 'declaracoes/pgdas', label: 'PGDAS' }, clientId: 7, clientName: 'Cliente' })
  }

  for (const slipNumber of [null, '', 'DAS-123']) {
    for (const declaredAt of [null, '2026-10-10']) {
      it(`matches the guide target for slip ${JSON.stringify(slipNumber)} and declaration ${declaredAt}`, async () => {
        const page = await modal()
        const period: MonitoringAssessmentPeriod = {
          period: '2026-09', declared_at: declaredAt, slip_number: slipNumber,
          rectified: false, slip_issued_at: null, due_on: null, slip_paid: null
        }
        const expected = slipNumber === 'DAS-123' || declaredAt !== null
        assert.equal(page.canDownload('guia', period), expected)
        assert.equal(page.canDownload('guia', period), whatsappGuideTarget([period]) !== null)
        for (const artifact of ['recibo', 'declaracao'] as const) {
          assert.equal(page.canDownload(artifact, period), false)
          assert.equal(page.canDownload(artifact, { ...period, declaration_number: 'DECL-123' }), true)
        }
      })
    }
  }

  it('rejects every artifact without an active period', async () => {
    const page = await modal()
    for (const artifact of ['guia', 'recibo', 'declaracao'] as const) assert.equal(page.canDownload(artifact, null), false)
  })
})
