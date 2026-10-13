/**
 * O `ResizeObserver` nativo lança se `observe()` recebe algo que não é
 * `Element` (nó de comentário do Suspense, `$el` de componente ainda sem
 * raiz). Esta proteção ignora esses alvos durante a montagem de componentes.
 *
 * Alvos inválidos são ignorados. Quando o ref passa a ser um elemento, o
 * watch chama `observe` de novo.
 */
export default defineNuxtPlugin({
  name: 'resize-observer-guard',
  enforce: 'pre',
  setup() {
    const original = ResizeObserver.prototype.observe
    ResizeObserver.prototype.observe = function (target: Element, options?: ResizeObserverOptions) {
      if (!(target instanceof Element)) return
      return original.call(this, target, options)
    }
  }
})
