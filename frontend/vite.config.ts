import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// 虹越智造平台 · 前端开发配置
// API 代理：/api → Laravel（本机 8000 被其他项目占用，虹越后端使用 8010）
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8010',
        changeOrigin: true,
      },
      '/mobile': {
        target: 'http://127.0.0.1:8010',
        changeOrigin: true,
      },
    },
  },
})
