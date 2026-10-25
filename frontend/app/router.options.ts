import type { RouterConfig } from 'nuxt/schema'
import type { RouteLocationNormalizedLoaded, RouteRecordRaw } from 'vue-router'

const ownerActivationPath = '/erp/ativar'

function suppressOwnerTokenFragmentScroll(routes: RouteRecordRaw[]) {
  return routes.map((route) => {
    if (route.path !== ownerActivationPath)
      return route

    const existingScrollToTop = route.meta?.scrollToTop as boolean | ((to: RouteLocationNormalizedLoaded, from: RouteLocationNormalizedLoaded) => boolean) | undefined

    return {
      ...route,
      meta: {
        ...route.meta,
        scrollToTop(to: RouteLocationNormalizedLoaded, from: RouteLocationNormalizedLoaded) {
          if (to.path === ownerActivationPath && to.hash.startsWith('#token='))
            return false

          return typeof existingScrollToTop === 'function'
            ? existingScrollToTop(to, from)
            : existingScrollToTop
        }
      }
    }
  })
}

export default {
  routes: suppressOwnerTokenFragmentScroll
} satisfies RouterConfig
