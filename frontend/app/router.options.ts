import type { RouterConfig } from 'nuxt/schema'

// Nuxt does not export this runtime module, but it is the installed default used by the generated router.
import installedNuxtRouterOptions from '../node_modules/nuxt/dist/pages/runtime/router.options.js'

const tokenContinuationPaths = new Set(['/erp/ativar', '/erp/convite', '/redefinir-senha'])
const nuxtRouterOptions = installedNuxtRouterOptions as { scrollBehavior: NonNullable<RouterConfig['scrollBehavior']> }

export default {
  scrollBehavior(to, from, savedPosition) {
    if (tokenContinuationPaths.has(to.path) && to.hash)
      return false

    return nuxtRouterOptions.scrollBehavior(to, from, savedPosition)
  }
} satisfies RouterConfig
