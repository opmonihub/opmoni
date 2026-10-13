import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import * as Vue from 'vue'
import { compileScript, parse } from 'vue/compiler-sfc'
import ts from 'typescript'
import QRCode from 'qrcode'
import * as apiErrors from '../app/composables/useApiError.ts'
import * as presentation from '../app/utils/whatsappChannelPresentation.ts'
import * as pageShell from '../app/utils/pageShell.ts'
import * as monitoring from '../app/utils/monitoringPresentation.ts'
import type { WhatsAppChannel } from '../app/types/whatsapp.ts'

const { descriptor } = parse(readFileSync(new URL('../app/pages/settings/whatsapp.vue', import.meta.url), 'utf8'))
const script = compileScript(descriptor, { id: 'whatsapp-lifecycle' })
const compiled = ts.transpileModule(script.content.replaceAll('import.meta.client', 'false'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ESNext, esModuleInterop: true }
}).outputText

function channel(status: WhatsAppChannel['status'], qrCode: string | null = null, expiresAt: string | null = null): WhatsAppChannel {
  return {
    status,
    qr_code: qrCode,
    qr_expires_at: expiresAt,
    whatsapp_jid: status === 'connected' ? '5511999999999@s.whatsapp.net' : null,
    last_error: status === 'error' ? 'Falha controlada' : null,
    connected_at: null,
    pairing_started_at: null
  }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: unknown) => void
  const promise = new Promise<T>((done, fail) => {
    resolve = done
    reject = fail
  })
  return { promise, resolve, reject }
}

async function setupPage(api: {
  startPairing: () => Promise<WhatsAppChannel>
  pairingStatus: () => Promise<WhatsAppChannel>
  reload?: () => Promise<WhatsAppChannel | null>
  initialError?: unknown
  disconnect?: () => Promise<WhatsAppChannel>
  read?: () => Promise<WhatsAppChannel | null>
}) {
  const current = Vue.ref<WhatsAppChannel | null>(null)
  const loadError = Vue.ref(api.initialError ?? null)
  const cleanups: (() => void)[] = []
  const globals = {
    ref: Vue.ref,
    computed: Vue.computed,
    definePageMeta() {},
    useToast: () => ({ add() {} }),
    useWhatsAppChannel: () => ({ ...api, channel: api.read ?? api.reload ?? (async () => current.value) }),
    useAsyncData: async () => ({
      data: current,
      status: Vue.ref('success'),
      error: loadError,
      refresh: async () => {
        current.value = await api.reload!()
        loadError.value = null
      }
    }),
    useRetryableLoad: (options: { refresh: () => Promise<unknown> }) => ({
      isLoading: Vue.ref(false),
      showError: Vue.computed(() => Boolean(loadError.value)),
      retry: options.refresh
    }),
    watch: (...args: Parameters<typeof Vue.watch>) => {
      const stop = Vue.watch(...args)
      cleanups.push(stop)
      return stop
    },
    onBeforeUnmount: (cleanup: () => void) => cleanups.push(cleanup)
  }
  const imports: Record<string, unknown> = {
    'vue': { ...Vue, withAsyncContext: (run: () => unknown) => [run(), () => {}] },
    'qrcode': QRCode,
    '~/composables/useApiError': apiErrors,
    '~/utils/monitoringPresentation': monitoring,
    '~/utils/pageShell': pageShell,
    '~/utils/whatsappChannelPresentation': presentation
  }
  const exports: { default?: { setup: (props: object, context: object) => Promise<unknown> } } = {}
  new Function('require', 'exports', ...Object.keys(globals), compiled)(
    (name: string) => imports[name], exports, ...Object.values(globals)
  )
  const state = await exports.default!.setup({}, { expose() {} }) as {
    status: Vue.ComputedRef<WhatsAppChannel['status']>
    qrExpired: Vue.ComputedRef<boolean>
    qrCode: Vue.Ref<string | null>
    qrImage: Vue.Ref<string | null>
    qrModalOpen: Vue.Ref<boolean>
    pairingModalConnected: Vue.ComputedRef<boolean>
    showError: Vue.ComputedRef<boolean>
    retry: () => Promise<void>
    submitPairing: () => Promise<void>
    poll: () => Promise<void>
    now: Vue.Ref<number>
    confirmDisconnect: () => Promise<void>
  }
  return { state, close: () => cleanups.forEach(cleanup => cleanup()) }
}

describe('ciclo de pareamento do Canal WhatsApp', () => {
  for (const staleStatus of ['pairing', 'connected', 'error', 502] as const) {
    it(`ignora polling antigo em ${staleStatus} após iniciar outro pareamento`, async (t) => {
      t.mock.timers.enable({ apis: ['Date', 'setInterval'], now: Date.UTC(2026, 9, 8, 12) })
      const oldExpires = new Date(Date.now() + 1000).toISOString()
      let next = channel('pairing', '2@qr-antigo', oldExpires)
      const oldPoll = deferred<WhatsAppChannel>()
      const currentPoll = deferred<WhatsAppChannel>()
      let pollingRequests = 0
      const page = await setupPage({
        startPairing: async () => next,
        pairingStatus: () => {
          pollingRequests += 1
          return pollingRequests === 1 ? oldPoll.promise : currentPoll.promise
        },
        reload: async () => channel('error')
      })
      t.after(page.close)
      await page.state.submitPairing()
      const obsoleteRequest = page.state.poll()
      t.mock.timers.tick(1000)
      page.state.now.value = Date.now()
      await Vue.nextTick()
      assert.equal(page.state.qrExpired.value, true)

      next = channel('pairing', '2@qr-atual', new Date(Date.now() + 60000).toISOString())
      await page.state.submitPairing()
      t.mock.timers.tick(4000)
      await Vue.nextTick()

      if (typeof staleStatus === 'number') oldPoll.reject({ statusCode: staleStatus })
      else oldPoll.resolve(channel(staleStatus, staleStatus === 'pairing' ? '2@qr-antigo' : null, staleStatus === 'pairing' ? oldExpires : null))
      await obsoleteRequest
      await Vue.nextTick()

      assert.equal(page.state.status.value, 'pairing')
      assert.equal(page.state.qrCode.value, '2@qr-atual')
      assert.equal(page.state.qrExpired.value, false)
      assert.equal(page.state.qrModalOpen.value, true)
      assert.match(page.state.qrImage.value ?? '', /^data:image\/png;base64,/)
      t.mock.timers.tick(4000)
      await Vue.nextTick()
      assert.equal(pollingRequests, 2, 'A resposta antiga não pode liberar outro GET enquanto o atual ainda aguarda')

      currentPoll.resolve(channel('connected'))
      await Vue.nextTick()
      await Vue.nextTick()
      assert.equal(page.state.status.value, 'connected')
      assert.equal(page.state.pairingModalConnected.value, true)
    })
  }

  it('ignora a releitura antiga do canal após um novo pareamento', async (t) => {
    t.mock.timers.enable({ apis: ['Date', 'setInterval'], now: Date.UTC(2026, 9, 8, 12) })
    let next = channel('pairing', '2@qr-antigo', new Date(Date.now() + 1000).toISOString())
    const oldRead = deferred<WhatsAppChannel>()
    const page = await setupPage({
      startPairing: async () => next,
      pairingStatus: async () => { throw { statusCode: 404 } },
      read: () => oldRead.promise,
      reload: () => oldRead.promise
    })
    t.after(page.close)
    await page.state.submitPairing()
    const obsoleteRequest = page.state.poll()
    await Vue.nextTick()
    t.mock.timers.tick(1000)
    page.state.now.value = Date.now()
    await Vue.nextTick()
    next = channel('pairing', '2@qr-atual', new Date(Date.now() + 60000).toISOString())
    await page.state.submitPairing()

    oldRead.resolve(channel('connected'))
    await obsoleteRequest

    assert.equal(page.state.status.value, 'pairing')
    assert.equal(page.state.qrCode.value, '2@qr-atual')
    assert.equal(page.state.qrExpired.value, false)
  })

  for (const ending of ['disconnect', 'unmount'] as const) {
    it(`descarta o polling pendente depois de ${ending === 'disconnect' ? 'desconectar' : 'sair da tela'}`, async (t) => {
      t.mock.timers.enable({ apis: ['Date', 'setInterval'], now: Date.UTC(2026, 9, 8, 12) })
      const pairing = channel('pairing', '2@qr-controlado', new Date(Date.now() + 60000).toISOString())
      const oldPoll = deferred<WhatsAppChannel>()
      let pollingRequests = 0
      const page = await setupPage({
        startPairing: async () => pairing,
        pairingStatus: () => {
          pollingRequests += 1
          return oldPoll.promise
        },
        disconnect: async () => channel('disconnected')
      })
      t.after(page.close)
      await page.state.submitPairing()
      const pending = page.state.poll()
      if (ending === 'disconnect') await page.state.confirmDisconnect()
      else page.close()

      oldPoll.resolve(pairing)
      await pending
      t.mock.timers.tick(4000)
      await Vue.nextTick()

      assert.equal(pollingRequests, 1, 'O GET pendente não pode rearmar o relógio encerrado')
      if (ending === 'disconnect') {
        assert.equal(page.state.status.value, 'disconnected')
        assert.equal(page.state.qrModalOpen.value, false)
        assert.equal(page.state.qrImage.value, null)
      }
    })
  }

  it('retoma o QR e o polling quando tentar novamente recupera um canal em pareamento', async (t) => {
    t.mock.timers.enable({ apis: ['Date', 'setInterval'], now: Date.UTC(2026, 9, 8, 12) })
    const pairing = channel('pairing', '2@qr-recuperado', new Date(Date.now() + 60000).toISOString())
    const page = await setupPage({
      initialError: { statusCode: 500 },
      reload: async () => pairing,
      startPairing: async () => pairing,
      pairingStatus: async () => channel('connected')
    })
    t.after(page.close)
    assert.equal(page.state.showError.value, true)

    await page.state.retry()

    assert.equal(page.state.showError.value, false)
    assert.equal(page.state.status.value, 'pairing')
    assert.match(page.state.qrImage.value ?? '', /^data:image\/png;base64,/)
    assert.equal(page.state.qrModalOpen.value, true)
    t.mock.timers.tick(4000)
    await Vue.nextTick()
    await Vue.nextTick()
    assert.equal(page.state.status.value, 'connected')
    assert.equal(page.state.pairingModalConnected.value, true)
  })

  it('detecta a conexão do novo pareamento depois que o QR anterior expirou', async (t) => {
    t.mock.timers.enable({ apis: ['Date', 'setInterval'], now: Date.UTC(2026, 9, 8, 12) })
    let next = channel('pairing', '2@primeiro-qr', new Date(Date.now() + 1000).toISOString())
    const page = await setupPage({ startPairing: async () => next, pairingStatus: async () => next })
    t.after(page.close)

    await page.state.submitPairing()
    t.mock.timers.tick(4000)
    await page.state.poll()
    await Vue.nextTick()
    assert.equal(page.state.qrExpired.value, true)

    next = channel('pairing', '2@segundo-qr', new Date(Date.now() + 60000).toISOString())
    await page.state.submitPairing()
    next = channel('connected')
    t.mock.timers.tick(4000)
    await Vue.nextTick()
    await Vue.nextTick()

    assert.equal(page.state.status.value, 'connected')
    assert.equal(page.state.pairingModalConnected.value, true)
    assert.equal(page.state.qrImage.value, null)
  })

  it('fecha o QR quando o polling informa que o canal foi desconectado', async (t) => {
    const pairing = channel('pairing', '2@qr-controlado', new Date(Date.now() + 60000).toISOString())
    const page = await setupPage({ startPairing: async () => pairing, pairingStatus: async () => channel('disconnected') })
    t.after(page.close)
    await page.state.submitPairing()
    assert.equal(page.state.qrModalOpen.value, true)

    await page.state.poll()

    assert.equal(page.state.status.value, 'disconnected')
    assert.equal(page.state.qrModalOpen.value, false)
    assert.equal(page.state.qrImage.value, null)
  })

  for (const [httpStatus, finalStatus] of [[404, 'disconnected'], [502, 'error']] as const) {
    it(`elimina o QR após polling ${httpStatus} e releitura em ${finalStatus}`, async (t) => {
      const pairing = channel('pairing', '2@qr-controlado', new Date(Date.now() + 60000).toISOString())
      const page = await setupPage({
        startPairing: async () => pairing,
        pairingStatus: async () => { throw { statusCode: httpStatus } },
        reload: async () => channel(finalStatus)
      })
      t.after(page.close)
      await page.state.submitPairing()
      await page.state.poll()

      assert.equal(page.state.status.value, finalStatus)
      assert.equal(page.state.qrModalOpen.value, false)
      assert.equal(page.state.qrImage.value, null)
    })
  }
})
