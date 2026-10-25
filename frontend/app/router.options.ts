import type { RouterConfig } from 'nuxt/schema'

// Nuxt does not export this runtime module, but it is the installed default used by the generated router.
// @ts-expect-error Nuxt ships this internal runtime file without a declaration.
import installedNuxtRouterOptions from '../node_modules/nuxt/dist/pages/runtime/router.options.js'

const ownerActivationPath = '/erp/ativar'
const nuxtRouterOptions = installedNuxtRouterOptions as { scrollBehavior: NonNullable<RouterConfig['scrollBehavior']> }

export default {
  scrollBehavior(to, from, savedPosition) {
    if (to.path === ownerActivationPath && to.hash.startsWith('#token='))
      return false

    return nuxtRouterOptions.scrollBehavior(to, from, savedPosition)
  }
} satisfies RouterConfig
