import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { createSSRApp, defineComponent, h, reactive, ref } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { compileVueComponent } from './helpers/compileVueComponent.ts'

interface ToastMessage {
  title: string
  description: string
  color: string
}

async function settingsDemo() {
  const messages: ToastMessage[] = []
  const logs: unknown[][] = []
  let submit: () => void = () => {
    throw new Error('Form was not rendered')
  }
  const page = compileVueComponent('pages/settings/index.vue', {
    ref,
    reactive,
    useToast: () => ({ add: (message: ToastMessage) => messages.push(message) }),
    console: { log: (...args: unknown[]) => logs.push(args) }
  })
  const app = createSSRApp(page)
  app.component('UForm', defineComponent({
    props: ['schema', 'state'],
    emits: ['submit'],
    setup(props, { slots, emit }) {
      submit = () => emit('submit', { data: props.schema.parse(props.state) })
      return () => h('form', slots.default?.())
    }
  }))
  app.component('UPageCard', defineComponent({
    props: ['title', 'description'],
    setup: (props, { slots }) => () => h('section', [
      props.title ? h('h2', props.title) : null,
      props.description ? h('p', props.description) : null,
      slots.default?.()
    ])
  }))
  app.component('UButton', defineComponent({
    props: ['label', 'type', 'color'],
    setup: props => () => h('button', { type: props.type }, props.label)
  }))
  app.component('UFormField', defineComponent({
    props: ['name', 'label', 'description', 'required', 'ui'],
    setup: (props, { slots }) => () => h('label', [props.label, slots.default?.()])
  }))
  for (const name of ['UInput', 'UTextarea', 'UAvatar', 'USeparator']) {
    app.component(name, defineComponent({
      props: ['modelValue', 'src', 'alt', 'size', 'autoresize'],
      setup: () => () => h('span')
    }))
  }
  const html = await renderToString(app)
  return { html, submit, messages, logs }
}

describe('sample Settings persistence disclosure', () => {
  it('labels the rendered profile and submit action as demonstration before submission', async () => {
    const { html } = await settingsDemo()
    assert.match(html, /<h2>Perfil \(demonstração\)<\/h2>/)
    assert.match(html, /Dados fictícios para demonstração\. As alterações não são salvas nem alteram seu perfil real\./)
    assert.match(html, /<button[^>]*type="submit"[^>]*>Simular alterações<\/button>/)
  })

  it('submits valid sample data with an explicit non-persistence notice, not success', async () => {
    const { submit, messages } = await settingsDemo()
    await submit()
    assert.equal(messages.length, 1)
    assert.equal(messages[0]?.title, 'Demonstração')
    assert.equal(messages[0]?.description, 'Nenhuma alteração foi salva. Este formulário é apenas demonstrativo.')
    assert.equal(messages[0]?.color, 'neutral')
  })

  it('does not log the submitted sample profile payload', async () => {
    const { submit, logs } = await settingsDemo()
    await submit()
    assert.deepEqual(logs, [])
  })
})
