import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { readClientEnvironment } from '../scripts/client-environment';

export default defineConfig(({ mode }) => {
  // Native ESM location also works with Vite's module-runner config loader.
  const environment = readClientEnvironment(resolve(dirname(fileURLToPath(import.meta.url)), `.env.${mode}`));
  const allowedHosts = (environment.VITE_ALLOWED_HOSTS || '')
    .split(',')
    .map((host) => host.trim())
    .filter(Boolean);
  const apiProxyTarget = environment.VITE_API_PROXY_TARGET ||
    (environment.PHP_PORT ? `http://127.0.0.1:${environment.PHP_PORT}` : 'http://127.0.0.1:20180');
  return {
  base: '/platform/',
  plugins: [vue()],
  define: {
    __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
    'import.meta.env.VITE_API_BASE_URL': JSON.stringify(environment.VITE_API_BASE_URL || ''),
  },
  server: {
    allowedHosts,
    proxy: {
      '/platformapi': { target: apiProxyTarget, changeOrigin: false },
      '/api': { target: apiProxyTarget, changeOrigin: false },
      '/favicon.ico': { target: apiProxyTarget, changeOrigin: false },
    },
  },
  };
});
