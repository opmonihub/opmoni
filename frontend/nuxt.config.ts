// https://nuxt.com/docs/api/configuration/nuxt-config
// Minimal ambient typing so `process.env` below typechecks without adding @types/node.
declare const process: { env: Record<string, string | undefined> }
export default defineNuxtConfig({
  modules: [
    '@nuxt/eslint',
    '@nuxt/ui',
    '@vueuse/nuxt'
  ],

  devtools: {
    enabled: true
  },

  css: ['~/assets/css/main.css'],

  // NUXT_PUBLIC_API_URL sobrescreve este default automaticamente (runtime config).
  // Browser e SSR passam pelo nginx: dev em http://localhost:3000, produção no
  // domínio público. Mesma origem nos dois casos, então não há CORS.
  runtimeConfig: {
    apiUrl: process.env.NUXT_API_URL ?? process.env.NUXT_PUBLIC_API_URL ?? 'http://localhost:3000',
    public: {
      apiUrl: process.env.NUXT_PUBLIC_API_URL ?? 'http://localhost:3000',
      siteUrl: process.env.NUXT_PUBLIC_SITE_URL ?? 'http://localhost:3000'
    }
  },

  compatibilityDate: '2026-06-30',

  eslint: {
    config: {
      stylistic: {
        commaDangle: 'never',
        braceStyle: '1tbs'
      }
    }
  }
})
