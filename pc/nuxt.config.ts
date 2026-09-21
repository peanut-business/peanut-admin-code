import defaultBrand from './generated/brand.json'
import { resolve } from 'node:path'
import { readClientEnvironment } from '../scripts/client-environment'

const fileEnv = readClientEnvironment(resolve(import.meta.dirname, '.env.production'))
const devProxyOrigin = fileEnv.NUXT_DEV_PROXY_ORIGIN ||
  (fileEnv.PHP_PORT ? `http://127.0.0.1:${fileEnv.PHP_PORT}` : 'http://127.0.0.1')
const devProxyTarget = fileEnv.NUXT_DEV_PROXY_TARGET || `${devProxyOrigin}/api`

export default defineNuxtConfig({
  compatibilityDate: '2024-11-01',
  devtools: { enabled: false },
  ssr: true,

  app: {
    baseURL: '/pc/',
    head: {
      title: defaultBrand.website.pc_title,
      meta: [
        { name: 'description', content: defaultBrand.website.pc_desc },
        { name: 'keywords', content: defaultBrand.website.pc_keywords },
      ],
      link: [{ rel: 'icon', href: '/brand/favicon.svg' }],
    },
  },

  modules: ['@element-plus/nuxt', '@pinia/nuxt', '@nuxtjs/tailwindcss'],

  elementPlus: {
    importStyle: 'css',
  },

  runtimeConfig: {
    upstreamOrigin: process.env.NUXT_UPSTREAM_ORIGIN || devProxyOrigin,
    forwardedProto: process.env.NUXT_FORWARDED_PROTO || 'http',
    trustedHosts: process.env.NUXT_TRUSTED_HOSTS || '',
    public: {
      apiBase: '',
    },
  },

  nitro: {
    devProxy: {
      '/api': {
        target: devProxyTarget,
        changeOrigin: false,
      },
      '/brand': {
        target: `${devProxyOrigin}/brand`,
        changeOrigin: false,
      },
      '/storage': {
        target: `${devProxyOrigin}/storage`,
        changeOrigin: false,
      },
    },
  },

  tailwindcss: {
    exposeConfig: true,
  },

  typescript: {
    strict: true,
  },
})
