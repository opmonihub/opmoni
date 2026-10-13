import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { createRenderer, h } from 'vue'

interface HostNode {
  type: string
  text?: string
  props: Record<string, unknown>
  children: HostNode[]
  parent: HostNode | null
}

function hostNode(type: string, text?: string): HostNode {
  return { type, text, props: {}, children: [], parent: null }
}

function textContent(node: HostNode): string {
  return node.text ?? node.children.map(textContent).join('')
}

describe('Work toolbar teleport', () => {
  it('renders actions when the toolbar target is mounted later in the same update', async () => {
    const { default: WorkToolbarTeleport } = await import('../app/components/work/WorkToolbarTeleport.ts')
    const root = hostNode('root')

    const renderer = createRenderer<HostNode, HostNode>({
      insert(child, parent, anchor) {
        child.parent = parent
        const index = anchor ? parent.children.indexOf(anchor) : -1
        if (index >= 0) parent.children.splice(index, 0, child)
        else parent.children.push(child)
      },
      remove(child) {
        const parent = child.parent
        if (!parent) return
        const index = parent.children.indexOf(child)
        if (index >= 0) parent.children.splice(index, 1)
        child.parent = null
      },
      createElement(type) {
        return hostNode(type)
      },
      createText(text) {
        return hostNode('#text', text)
      },
      createComment(text) {
        return hostNode('#comment', text)
      },
      setText(node, text) {
        node.text = text
      },
      setElementText(node, text) {
        node.children = [hostNode('#text', text)]
        node.children[0]!.parent = node
      },
      parentNode(node) {
        return node.parent
      },
      nextSibling(node) {
        const parent = node.parent
        if (!parent) return null
        return parent.children[parent.children.indexOf(node) + 1] ?? null
      },
      querySelector(selector) {
        const id = selector.startsWith('#') ? selector.slice(1) : null
        const visit = (node: HostNode): HostNode | null => {
          if (id && node.props.id === id) return node
          for (const child of node.children) {
            const match = visit(child)
            if (match) return match
          }
          return null
        }
        return visit(root)
      },
      patchProp(node, key, _previousValue, nextValue) {
        node.props[key] = nextValue
      },
      setScopeId() {},
      cloneNode(node) {
        return { ...node, props: { ...node.props }, children: [...node.children], parent: null }
      },
      insertStaticContent() {
        const node = hostNode('#static')
        return [node, node]
      }
    })

    const App = {
      render: () => h('main', [
        h(WorkToolbarTeleport, null, { default: () => h('button', 'Atualizar') }),
        h('div', { id: 'work-toolbar-actions' })
      ])
    }

    renderer.createApp(App).mount(root)

    const target = root.children[0]!.children.find(node => node.props.id === 'work-toolbar-actions')
    assert.ok(target)
    assert.equal(textContent(target), 'Atualizar')
  })
})
