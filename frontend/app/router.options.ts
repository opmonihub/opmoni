import { isChangingPage } from '#app/components/utils'
import { useNuxtApp } from '#app/nuxt'
import { useRouter } from '#app/composables/router'
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

function getHashElementScrollMarginTop(selector: string) {
  try {
    const element = document.querySelector(selector)
    if (element) {
      return (Number.parseFloat(getComputedStyle(element).scrollMarginTop) || 0)
        + (Number.parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop) || 0)
    }
  } catch {
    // Invalid selectors are ignored by Nuxt's default scroll behavior too.
  }

  return 0
}

function calculatePosition(to: RouteLocationNormalizedLoaded, from: RouteLocationNormalizedLoaded, savedPosition: { left: number, top: number } | null, hashScrollBehavior: ScrollBehaviorType) {
  if (savedPosition)
    return savedPosition

  if (to.hash) {
    return {
      el: to.hash,
      top: getHashElementScrollMarginTop(to.hash),
      behavior: isChangingPage(to, from) ? hashScrollBehavior : 'instant'
    }
  }

  return { left: 0, top: 0 }
}

type ScrollBehaviorType = 'auto' | 'smooth' | 'instant' | undefined

export default {
  routes: suppressOwnerTokenFragmentScroll,
  scrollBehavior(to, from, savedPosition) {
    const nuxtApp = useNuxtApp()
    const router = useRouter()
    const hashScrollBehavior = router.options?.scrollBehaviorType ?? 'auto'

    if (to.path.replace(/\/$/, '') === from.path.replace(/\/$/, '')) {
      if (from.hash && !to.hash)
        return savedPosition ?? { left: 0, top: 0 }

      if (to.hash) {
        if (to.path === ownerActivationPath && to.hash.startsWith('#token='))
          return false

        return {
          el: to.hash,
          top: getHashElementScrollMarginTop(to.hash),
          behavior: hashScrollBehavior
        }
      }

      return false
    }

    if ((typeof to.meta.scrollToTop === 'function' ? to.meta.scrollToTop(to, from) : to.meta.scrollToTop) === false)
      return false

    if (from.matched.length === 0)
      return calculatePosition(to, from, savedPosition, hashScrollBehavior)

    return new Promise((resolve) => {
      const doScroll = () => {
        requestAnimationFrame(() => {
          if (router.currentRoute.value.fullPath !== to.fullPath) {
            resolve(false)
            return
          }

          resolve(calculatePosition(to, from, savedPosition, hashScrollBehavior))
        })
      }

      nuxtApp.hooks.hookOnce('page:loading:end', () => {
        const transitionPromise = nuxtApp['~transitionPromise']
        if (transitionPromise)
          transitionPromise.then(doScroll)
        else
          doScroll()
      })
    })
  }
} satisfies RouterConfig
