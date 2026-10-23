const buildEnvironment = (globalThis as { process?: { env?: Record<string, string | undefined> } }).process?.env

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  modules: [
    '@nuxt/eslint',
    '@nuxt/ui',
    '@vueuse/nuxt'
  ],

  devtools: {
    // Its client imports the Vue tracer overlay even when componentInspector is false.
    // Keep the overlay from intercepting Paseo's element picker.
    enabled: false
  },

  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    backendUrl: 'http://127.0.0.1:8000',
    ingressKey: ''
  },

  devServer: {
    // Wait for the application without rendering Nuxt's animated build screen.
    loadingTemplate: () => ''
  },

  compatibilityDate: '2026-06-30',

  nitro: { output: { dir: buildEnvironment?.NITRO_OUTPUT_DIR || '.output' } },

  hooks: {
    'nitro:config'(config) {
      if (!config.dev) return
      config.devHandlers ||= []
      config.devHandlers.unshift({
        handler(event) {
          // Runs on Nitro's physical dev listener before its Unix worker proxy.
          const headers = event.node.req.headers
          for (const name of Object.keys(headers)) {
            if (name.startsWith('x-opmoni-') || name.startsWith('x-forwarded-') || name === 'forwarded' || name === 'x-real-ip') Reflect.deleteProperty(headers, name)
          }
          headers['x-opmoni-dev-key'] = buildEnvironment?.NUXT_INGRESS_KEY || ''
          headers['x-opmoni-dev-ip'] = event.node.req.socket.remoteAddress || ''
          headers['x-opmoni-dev-proto'] = 'encrypted' in event.node.req.socket && event.node.req.socket.encrypted ? 'https' : 'http'
        }
      })
    }
  },

  eslint: {
    config: {
      stylistic: {
        commaDangle: 'never',
        braceStyle: '1tbs'
      }
    }
  },

  fonts: { providers: { google: false, googleicons: false, bunny: false, fontshare: false } },
  icon: { provider: 'server' }
})
