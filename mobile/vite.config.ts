import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// 车间移动端 H5（独立轻量应用，扫码即用）
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5174,
    proxy: {
      '/api': { target: 'http://127.0.0.1:8010', changeOrigin: true },
      '/mobile': { target: 'http://127.0.0.1:8010', changeOrigin: true },
    },
  },
})
