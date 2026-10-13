import { Teleport, createVNode, defineComponent } from 'vue'

export default defineComponent({
  name: 'WorkToolbarTeleport',
  setup(_, { slots }) {
    return () => createVNode(
      Teleport,
      { to: '#work-toolbar-actions', defer: true },
      slots.default?.()
    )
  }
})
