import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// The dev server proxies /api to the Laravel backend so the SPA can use
// same-origin relative URLs. Override the target with VITE_API_PROXY.
export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5188,
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY || 'http://127.0.0.1:8811',
        changeOrigin: true,
      },
    },
  },
})
